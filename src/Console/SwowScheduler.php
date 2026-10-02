<?php

namespace DagaSmart\TaskSchedule\Console;

use Swow\Coroutine;
use Swow\Sync\WaitGroup;
use Swow\Signal;
use Swow\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;
use DagaSmart\TaskSchedule\Models\TaskScheduleDispatch;
use DagaSmart\TaskSchedule\Enums\TaskStatus;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;

/**
 * Swow 协程调度器
 *
 * 核心特性：
 * - 毫秒级精度的定时调度（秒/分/时/天/周/月）
 * - 协程并发执行（单进程万级并发）
 * - PostgreSQL Advisory Lock 分布式防重叠
 * - FOR UPDATE SKIP LOCKED 原子化任务领取
 * - 优雅退出和信号处理
 * - 自动重连和故障恢复
 */
class SwowScheduler
{
    /**
     * 调度器运行状态
     */
    private bool $running = false;

    /**
     * Worker 标识
     */
    private string $workerId;

    /**
     * 服务器标识
     */
    private string $serverId;

    /**
     * 事件循环 tick 间隔（微秒）
     */
    private int $tickInterval;

    /**
     * 最大协程并发数
     */
    private int $maxConcurrency;

    /**
     * 信号通道
     */
    private ?Channel $signalChannel = null;

    /**
     * 任务执行通道（协程池）
     */
    private ?Channel $taskChannel = null;

    /**
     * 活跃协程计数
     */
    private int $activeCoroutines = 0;

    /**
     * 统计信息
     */
    private array $stats = [
        'tasks_executed' => 0,
        'tasks_succeeded' => 0,
        'tasks_failed' => 0,
        'tasks_skipped' => 0,
        'total_duration' => 0.0,
    ];

    /**
     * 上次心跳时间
     */
    private float $lastHeartbeat = 0;

    /**
     * 协程栈
     */
    private array $coroutines = [];

    /**
     * 分层时间轮：仅承载秒级任务，分钟级以上由 Laravel Schedule 接管。
     * 这里必须用属性提升语法之外的普通属性，因为需要按接口契约延迟到 run() 里初始化。
     */
    private TimingWheel $timingWheel;

    /**
     * 版本号：当数据库里的 max(version) 大于此值时触发热重载。
     * 任务保存/更新时由 service 自增 version，worker 据此感知变更而无需重启。
     */
    private int $knownVersion = 0;

    public function __construct()
    {
        $this->workerId = uniqid('worker_', true);
        $this->serverId = gethostname() . '_' . getmypid();
        $this->tickInterval = (int) (Config::get('schedule.swow.loop_tick_ms', 10) * 1000); // 转微秒
        $this->maxConcurrency = Config::get('schedule.swow.max_coroutines', 1024);

        // 设置协程栈大小
        if (function_exists('swow_coroutine_set_stack_size')) {
            swow_coroutine_set_stack_size(
                Config::get('schedule.swow.stack_size', 8388608)
            );
        }
    }

    /**
     * 启动调度器
     */
    public function run(): void
    {
        $this->running = true;
        $this->lastHeartbeat = microtime(true);

        $this->logInfo("Swow Scheduler starting | Worker: {$this->workerId} | Server: {$this->serverId}");

        // 初始化信号监听
        $this->setupSignalHandler();

        // 初始化任务通道
        $this->taskChannel = new Channel($this->maxConcurrency);

        // 初始化时间轮并加载秒级任务
        $this->timingWheel = new TimingWheel();
        $this->reloadSecondLevelTasks();
        $this->knownVersion = $this->currentVersion();

        // 启动调度循环
        Coroutine::run(function () {
            $this->scheduleLoop();
        });

        // 启动任务执行协程池
        for ($i = 0; $i < min($this->maxConcurrency, 64); $i++) {
            Coroutine::run(function () {
                $this->workerLoop();
            });
        }

        // 启动心跳协程
        Coroutine::run(function () {
            $this->heartbeatLoop();
        });

        // 启动统计上报协程
        Coroutine::run(function () {
            $this->metricsLoop();
        });

        // 主循环：等待信号
        $this->mainLoop();

        $this->logInfo("Swow Scheduler stopped gracefully");
    }

    /**
     * 调度主循环
     */
    private function scheduleLoop(): void
    {
        $this->logInfo("Schedule loop started");

        while ($this->running) {
            try {
                $startTime = microtime(true);

                // 按精度级别分别处理
                $this->dispatchDueTasks();

                // 计算需要 sleep 的时间
                $elapsed = (microtime(true) - $startTime) * 1000000; // 微秒
                $sleepTime = max(0, $this->tickInterval - $elapsed);

                if ($sleepTime > 0) {
                    usleep((int) $sleepTime);
                }
            } catch (\Throwable $e) {
                $this->logError("Schedule loop error: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // 错误后短暂休眠，避免快速失败循环
                usleep(1000000); // 1秒
            }
        }

        $this->logInfo("Schedule loop stopped");
    }

    /**
     * 分发到期的任务
     *
     * 引擎分流（这是整套调度器最关键的约定，务必保持）：
     *   - precision = SECOND：由本 Swow Worker 的时间轮驱动，每个 tick 推进一格
     *   - precision >= MINUTE（分/时/天/周/月）：由 Laravel 原生 Schedule 驱动，
     *     在 TaskScheduleServiceProvider::schedule() 里注册，cron 每分钟唤醒一次。
     *
     * 之所以不让 Swow 同时处理分钟级以上任务：
     *   那样会造成双引擎重复执行。曾经的实现用 shouldCheckPrecision() 在整秒点
     *   判断「是否到了分钟/小时/天的边界」，但 tick 并不保证恰好落在 0 秒，
     *   漏执行与重复执行都不可避免，且等于用常驻进程冗余实现了一个 cron。
     */
    private function dispatchDueTasks(): void
    {
        // 仅处理秒级：把时间轮里到期的条目取出，投递到执行协程池
        $fired = $this->timingWheel->advance((int) microtime(true));

        foreach ($fired as $entry) {
            $this->taskChannel->push($entry->toPayload());
            $this->stats['tasks_executed']++;
        }
    }

    /**
     * 从数据库重载秒级任务到时间轮。
     * 触发时机：worker 启动、以及配置热更新信号。
     */
    private function reloadSecondLevelTasks(): void
    {
        try {
            $tasks = TaskSchedule::query()
                ->active()
                ->where('precision', PrecisionLevel::SECOND->value)
                ->get();
        } catch (\Throwable $e) {
            $this->logError("Failed to reload second-level tasks: " . $e->getMessage());
            return;
        }

        $nowTs = (int) microtime(true);

        // 以 taskId 为键比对，决定新增 / 取消，避免整表重建造成任务重排
        $freshIds = [];
        foreach ($tasks as $task) {
            $freshIds[] = $task->id;
            if (!$this->timingWheel->has($task->id)) {
                $this->timingWheel->schedule(
                    TaskEntry::fromArray($task->toArray()),
                    $nowTs
                );
            }
        }

        // 清理已停用 / 已删除的任务
        foreach ($this->timingWheel->ids() as $registeredId) {
            if (!in_array($registeredId, $freshIds, true)) {
                $this->timingWheel->cancel($registeredId);
            }
        }

        $stats = $this->timingWheel->stats();
        $this->logInfo(sprintf(
            'Second-level tasks reloaded: active=%d wheel_registered=%d',
            count($tasks),
            $stats['registered']
        ));
    }

    /**
     * 回退方案：使用 ORM 方式领取秒级任务。
     *
     * 仅当 claim_due_tasks() 存储函数不可用时调用。常规路径下不再承担
     * 分钟级以上任务的分发（见 dispatchDueTasks() 的引擎分流说明）。
     */
    private function dispatchViaOrm(): void
    {
        $tasks = TaskSchedule::query()
            ->active()
            ->where('precision', PrecisionLevel::SECOND->value)
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('next_run_at', 'asc')
            ->limit(50)
            ->get();

        $nowTs = (int) microtime(true);
        foreach ($tasks as $task) {
            // 防重叠检查
            if ($task->without_overlapping) {
                if (!$this->tryAcquireLock($task)) {
                    continue;
                }
            }

            // 投递到执行通道
            $this->taskChannel->push($task->toArray());

            // 更新下次执行时间
            $task->update([
                'last_run_at' => now(),
                'next_run_at' => $task->calculateNextRun(),
            ]);

            $this->stats['tasks_executed']++;
        }

        // 同时把这些任务注册进时间轮，使后续 tick 走快速路径
        foreach ($tasks as $task) {
            if (!$this->timingWheel->has($task->id)) {
                $this->timingWheel->schedule(
                    TaskEntry::fromArray($task->toArray()),
                    $nowTs
                );
            }
        }
    }

    /**
     * 尝试获取分布式锁
     */
    private function tryAcquireLock(TaskSchedule $task): bool
    {
        $lockNamespace = Config::get('schedule.locking.namespace', 0x5441534B);
        $lockKey = ($lockNamespace << 32) | ($task->id & 0xFFFFFFFF);

        try {
            $acquired = DB::selectOne(
                'SELECT pg_try_advisory_xact_lock(?) as acquired',
                [$lockKey]
            );

            return (bool) ($acquired->acquired ?? false);
        } catch (\Throwable $e) {
            // 非 PostgreSQL 环境使用 Cache 锁
            return Cache::lock(
                "task_schedule:{$task->id}",
                $task->overlap_release_minutes * 60
            )->get();
        }
    }

    /**
     * Worker 执行循环
     */
    private function workerLoop(): void
    {
        while ($this->running) {
            try {
                // 从通道获取任务
                $taskData = $this->taskChannel->pop();

                if ($taskData === null) {
                    break;
                }

                // 创建协程执行任务
                $this->activeCoroutines++;
                Coroutine::run(function () use ($taskData) {
                    try {
                        $this->executeTask($taskData);
                    } catch (\Throwable $e) {
                        $this->logError("Task execution error: " . $e->getMessage());
                    } finally {
                        $this->activeCoroutines--;
                    }
                });
            } catch (\Throwable $e) {
                $this->logError("Worker loop error: " . $e->getMessage());
                usleep(100000); // 100ms
            }
        }
    }

    /**
     * 执行任务
     */
    private function executeTask(array $taskData): void
    {
        $taskId = $taskData['id'] ?? 0;
        $command = $taskData['command'] ?? '';
        $taskType = $taskData['task_type'] ?? 'command';
        $dispatchId = uniqid('disp_', true);

        $startTime = microtime(true);
        $this->logInfo("Executing task [ID:{$taskId}] type={$taskType} cmd={$command}");

        // 创建日志记录
        $log = $this->createLogRecord($taskData, $dispatchId);

        try {
            // 防重叠检查
            if (($taskData['without_overlapping'] ?? false)) {
                if (!$this->acquireExecutionLock($taskId, $dispatchId)) {
                    $log->update([
                        'status' => TaskStatus::SKIPPED->value,
                        'finished_at' => now(),
                    ]);
                    $this->stats['tasks_skipped']++;
                    return;
                }
            }

            // 根据任务类型执行
            $exitCode = match ($taskType) {
                'command' => $this->executeCommand($taskData),
                'job' => $this->dispatchJob($taskData),
                'url' => $this->callUrl($taskData),
                'shell' => $this->executeShell($taskData),
                'closure' => $this->executeClosure($taskData),
                default => throw new \InvalidArgumentException("Unknown task type: {$taskType}"),
            };

            $duration = round(microtime(true) - $startTime, 4);

            // 更新日志
            $status = $exitCode === 0 ? TaskStatus::SUCCESS : TaskStatus::FAILED;
            $log->update([
                'status' => $status->value,
                'exit_code' => $exitCode,
                'duration' => $duration,
                'finished_at' => now(),
                'memory_peak' => memory_get_peak_usage(true),
            ]);

            // 更新统计
            if ($exitCode === 0) {
                $this->stats['tasks_succeeded']++;
            } else {
                $this->stats['tasks_failed']++;
            }
            $this->stats['total_duration'] += $duration;

            // 慢任务告警
            $slowThreshold = Config::get('schedule.monitoring.slow_task_threshold', 30);
            if ($duration > $slowThreshold) {
                $this->logWarning("Slow task detected [ID:{$taskId}] duration={$duration}s");
            }

            $this->logInfo("Task completed [ID:{$taskId}] exit={$exitCode} duration={$duration}s");
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 4);

            $log->update([
                'status' => TaskStatus::FAILED->value,
                'exit_code' => 1,
                'duration' => $duration,
                'output' => ['error' => $e->getMessage()],
                'finished_at' => now(),
            ]);

            $this->stats['tasks_failed']++;
            $this->logError("Task failed [ID:{$taskId}] error={$e->getMessage()}");
        } finally {
            // 释放执行锁
            if (($taskData['without_overlapping'] ?? false)) {
                $this->releaseExecutionLock($dispatchId);
            }
        }
    }

    /**
     * 执行 Artisan 命令
     */
    private function executeCommand(array $task): int
    {
        $command = trim($task['command'] ?? '');
        $parameters = $task['parameters'] ?? null;

        // 清理命令
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        // 解析参数
        $params = [];
        if ($parameters) {
            if (is_array($parameters) || is_object($parameters)) {
                $params = (array) $parameters;
            } else {
                $params = preg_split('/\s+/', trim($parameters));
            }
        }

        // 后台运行
        if ($task['in_background'] ?? false) {
            $this->runInBackground($command, $params);
            return 0;
        }

        // 前台运行
        return $this->runForeground($command, $params, $task);
    }

    /**
     * 前台执行命令
     */
    private function runForeground(string $command, array $params, array $task): int
    {
        $fullCommand = sprintf(
            '%s %s %s',
            PHP_BINARY,
            'artisan',
            $command
        );

        if (!empty($params)) {
            $fullCommand .= ' ' . implode(' ', array_map('escapeshellarg', $params));
        }

        // 超时设置
        $timeout = $task['max_runtime'] ?? 0;
        if ($timeout > 0) {
            $fullCommand = sprintf('timeout %d %s', $timeout, $fullCommand);
        }

        // 输出重定向
        if (!empty($task['output_file_path'])) {
            $outputPath = Config::get('schedule.output.path') . '/' . $task['output_file_path'];
            $append = $task['output_append'] ? '>>' : '>';
            $fullCommand .= " {$append} {$outputPath} 2>&1";
        }

        $exitCode = 0;
        system($fullCommand, $exitCode);

        return $exitCode;
    }

    /**
     * 后台执行命令
     */
    private function runInBackground(string $command, array $params): void
    {
        $fullCommand = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            PHP_BINARY,
            'artisan',
            $command . (!empty($params) ? ' ' . implode(' ', array_map('escapeshellarg', $params)) : '')
        );

        exec($fullCommand);
    }

    /**
     * 派发队列任务
     */
    private function dispatchJob(array $task): int
    {
        $jobClass = $task['command'] ?? '';
        $parameters = $task['parameters'] ?? [];

        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Job class not found: {$jobClass}");
        }

        $job = new $jobClass(...(array) $parameters);
        dispatch($job);

        return 0;
    }

    /**
     * 调用 HTTP URL
     */
    private function callUrl(array $task): int
    {
        $url = $task['command'] ?? '';
        $parameters = (array) ($task['parameters'] ?? []);

        $method = $parameters['method'] ?? 'GET';
        $headers = $parameters['headers'] ?? [];
        $body = $parameters['body'] ?? null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $task['max_runtime'] ?? 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300 ? 0 : 1;
    }

    /**
     * 执行 Shell 脚本
     */
    private function executeShell(array $task): int
    {
        $script = $task['command'] ?? '';

        if (!file_exists($script) && !is_executable($script)) {
            throw new \RuntimeException("Shell script not found or not executable: {$script}");
        }

        $exitCode = 0;
        system($script, $exitCode);

        return $exitCode;
    }

    /**
     * 执行闭包
     */
    private function executeClosure(array $task): int
    {
        // 通过序列化存储的闭包无法安全反序列化
        // 此处仅记录，实际执行需要通过应用内注册的回调
        throw new \RuntimeException("Closure execution not supported in database-driven scheduler");
    }

    /**
     * 创建日志记录
     */
    private function createLogRecord(array $task, string $dispatchId): TaskScheduleLog
    {
        return TaskScheduleLog::create([
            'task_id' => $task['id'] ?? 0,
            'task_name' => $task['task_name'] ?? '',
            'command' => $task['command'] ?? '',
            'description' => $task['description'] ?? '',
            'status' => TaskStatus::RUNNING->value,
            'worker_id' => $this->workerId,
            'started_at' => now(),
        ]);
    }

    /**
     * 获取执行锁
     */
    private function acquireExecutionLock(int $taskId, string $dispatchId): bool
    {
        try {
            $result = DB::selectOne(
                'SELECT try_acquire_task_lock(?, ?, ?, ?, ?) as acquired',
                [$taskId, $dispatchId, $this->workerId, $this->serverId, 300]
            );

            return (bool) ($result->acquired ?? false);
        } catch (\Throwable $e) {
            // 回退到 Cache 锁
            return Cache::lock(
                "task_exec:{$taskId}",
                Config::get('schedule.locking.timeout', 300)
            )->get();
        }
    }

    /**
     * 释放执行锁
     */
    private function releaseExecutionLock(string $dispatchId): void
    {
        try {
            DB::statement('SELECT release_task_lock(?, ?)', [
                $dispatchId,
                TaskStatus::SUCCESS->value,
            ]);
        } catch (\Throwable $e) {
            // 忽略释放锁的错误
        }
    }

    /**
     * 心跳循环
     */
    private function heartbeatLoop(): void
    {
        $interval = Config::get('schedule.precision.heartbeat_interval', 30);

        while ($this->running) {
            usleep($interval * 1000000);

            $this->lastHeartbeat = microtime(true);

            // 上报心跳
            $this->reportHeartbeat();

            // 清理过期记录
            $this->cleanupStaleRecords();
        }
    }

    /**
     * 上报心跳
     */
    private function reportHeartbeat(): void
    {
        $data = [
            'worker_id' => $this->workerId,
            'server_id' => $this->serverId,
            'timestamp' => now()->toIso8601String(),
            'active_coroutines' => $this->activeCoroutines,
            'stats' => $this->stats,
        ];

        // 写入缓存作为心跳
        Cache::put(
            "scheduler:heartbeat:{$this->workerId}",
            $data,
            now()->addMinutes(5)
        );
    }

    /**
     * 清理过期记录
     */
    private function cleanupStaleRecords(): void
    {
        try {
            DB::statement('SELECT cleanup_dispatch_records(?)', [24]);
        } catch (\Throwable $e) {
            // 忽略错误
        }
    }

    /**
     * 指标上报循环
     */
    private function metricsLoop(): void
    {
        $interval = 60; // 每分钟上报

        while ($this->running) {
            usleep($interval * 1000000);

            // Prometheus 指标上报
            $prefix = Config::get('schedule.monitoring.metrics_prefix', 'task_schedule');

            // 这里可以集成 Prometheus 客户端
            // 示例：记录到日志供采集
            $this->logInfo("Metrics | {$prefix}_tasks_total={$this->stats['tasks_executed']} " .
                "| success={$this->stats['tasks_succeeded']} " .
                "| failed={$this->stats['tasks_failed']} " .
                "| skipped={$this->stats['tasks_skipped']} " .
                "| avg_duration=" . round($this->stats['total_duration'] / max($this->stats['tasks_executed'], 1), 4) .
                "| active_coroutines={$this->activeCoroutines}");
        }
    }

    /**
     * 设置信号处理器
     */
    private function setupSignalHandler(): void
    {
        $this->signalChannel = new Channel(1);

        Coroutine::run(function () {
            while ($this->running) {
                try {
                    $signal = $this->signalChannel->pop();
                    $this->handleSignal($signal);
                } catch (\Throwable $e) {
                    break;
                }
            }
        });

        // 注册信号监听
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGQUIT, [$this, 'handleSignal']);
        }
    }

    /**
     * 处理信号
     */
    private function handleSignal($signal): void
    {
        $this->logInfo("Received signal: {$signal}");

        switch ($signal) {
            case SIGTERM:
            case SIGINT:
            case SIGQUIT:
                $this->shutdown();
                break;
        }
    }

    /**
     * 主循环
     */
    private function mainLoop(): void
    {
        // 上次版本检测时间：用秒级节流，避免每个 100ms tick 都打一次 SQL
        $lastVersionCheck = 0;

        while ($this->running) {
            // 处理信号（非阻塞）
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // 检查心跳超时
            if (microtime(true) - $this->lastHeartbeat > 300) {
                $this->logWarning("Heartbeat timeout detected");
                $this->lastHeartbeat = microtime(true);
            }

            // 节流检查版本号：每秒最多一次
            $now = time();
            if ($now - $lastVersionCheck >= 1) {
                $lastVersionCheck = $now;
                $current = $this->currentVersion();
                if ($current !== $this->knownVersion) {
                    $this->logInfo("Task version changed: {$this->knownVersion} -> {$current}, reloading");
                    $this->knownVersion = $current;
                    $this->reloadSecondLevelTasks();
                }
            }

            usleep(100000); // 100ms
        }

        // 等待所有协程完成
        $this->waitForCompletion();
    }

    /**
     * 读取当前任务表的版本号（取最大值）。
     * version 由 TaskScheduleService 在保存时自增，作为 worker 热更新的触发信号。
     * 返回 0 表示表为空或尚不可用。
     */
    private function currentVersion(): int
    {
        try {
            $table = config('schedule.table', 'task_schedule');
            $row = DB::table($table)->max('version');
            return (int) ($row ?? 0);
        } catch (\Throwable $e) {
            return $this->knownVersion;
        }
    }

    /**
     * 等待所有协程完成
     */
    private function waitForCompletion(): void
    {
        $timeout = 30; // 最多等待30秒
        $start = time();

        while ($this->activeCoroutines > 0 && (time() - $start) < $timeout) {
            usleep(100000); // 100ms
        }

        if ($this->activeCoroutines > 0) {
            $this->logWarning("Force shutdown with {$this->activeCoroutines} active coroutines");
        }
    }

    /**
     * 优雅关闭
     */
    public function shutdown(): void
    {
        $this->logInfo("Initiating graceful shutdown...");
        $this->running = false;

        // 关闭任务通道
        if ($this->taskChannel) {
            $this->taskChannel->close();
        }

        // 清理心跳
        Cache::forget("scheduler:heartbeat:{$this->workerId}");

        $this->logInfo("Shutdown complete");
    }

    /**
     * 记录信息日志
     */
    private function logInfo(string $message): void
    {
        echo sprintf(
            "[%s] [Scheduler:%s] %s\n",
            date('Y-m-d H:i:s'),
            $this->workerId,
            $message
        );

        Log::channel('stack')->info($message, ['worker' => $this->workerId]);
    }

    /**
     * 记录错误日志
     */
    private function logError(string $message, array $context = []): void
    {
        echo sprintf(
            "[%s] [Scheduler:%s] ERROR: %s\n",
            date('Y-m-d H:i:s'),
            $this->workerId,
            $message
        );

        Log::channel('stack')->error($message, array_merge($context, ['worker' => $this->workerId]));
    }

    /**
     * 记录警告日志
     */
    private function logWarning(string $message): void
    {
        echo sprintf(
            "[%s] [Scheduler:%s] WARN: %s\n",
            date('Y-m-d H:i:s'),
            $this->workerId,
            $message
        );

        Log::channel('stack')->warning($message, ['worker' => $this->workerId]);
    }
}
