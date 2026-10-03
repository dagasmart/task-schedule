<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console;

use Swow\Coroutine;
use Swow\Signal;
use Swow\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Enums\TaskState;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;
use DagaSmart\TaskSchedule\Engine\{TaskEntry, TimingWheel};

/**
 * Swow 协程调度器
 */
class SwowScheduler
{
    private bool $running = false;
    private string $workerId;
    private string $serverId;
    private int $tickInterval;
    private int $maxConcurrency;

    private ?Channel $taskChannel = null;

    private int $activeCoroutines = 0;

    private array $stats = [
        'tasks_executed' => 0,
        'tasks_succeeded' => 0,
        'tasks_failed' => 0,
        'tasks_skipped' => 0,
        'total_duration' => 0.0,
    ];

    private float $lastHeartbeat = 0;

    private TimingWheel $timingWheel;
    private int $knownVersion = 0;

    /** @var array<int, array{taskId: int, lockKey: int, type: string, cacheLock?: \Illuminate\Cache\Lock}> 已获取的锁 */
    private array $acquiredLocks = [];

    public function __construct()
    {
        $this->workerId = uniqid('worker_', true);
        $this->serverId = gethostname() . '_' . getmypid();
        $this->tickInterval = (int) (Config::get('schedule.swow.loop_tick_ms', 10) * 1000);
        $this->maxConcurrency = Config::get('schedule.swow.max_coroutines', 1024);

        if (function_exists('swow_coroutine_set_stack_size')) {
            swow_coroutine_set_stack_size(
                Config::get('schedule.swow.stack_size', 8388608)
            );
        }
    }

    public function run(): void
    {
        $this->running = true;
        $this->lastHeartbeat = microtime(true);

        $this->logInfo("Swow Scheduler starting | Worker: {$this->workerId} | Server: {$this->serverId}");

        // 信号监听：Windows 下 Swow Signal::wait 行为不一致，跳过
        $this->startSignalWatcher();

        $this->taskChannel = new Channel($this->maxConcurrency);

        $this->timingWheel = new TimingWheel();
        $this->reloadSecondLevelTasks();
        $this->knownVersion = $this->currentVersion();

        Coroutine::run(function () {
            $this->scheduleLoop();
        });

        for ($i = 0; $i < min($this->maxConcurrency, 64); $i++) {
            Coroutine::run(function () {
                $this->workerLoop();
            });
        }

        Coroutine::run(function () {
            $this->heartbeatLoop();
        });

        Coroutine::run(function () {
            $this->metricsLoop();
        });

        $this->mainLoop();

        $this->logInfo("Swow Scheduler stopped gracefully");
    }

    /* ==================== 信号 ==================== */

    private function startSignalWatcher(): void
    {
        // Windows 下 Swow\Signal::wait 只接受单 int、不支持数组，
        // 且 Windows 无 Unix 信号语义，暂时跳过优雅退出。
        // TODO: Linux 下按 OS 重写，使用单信号循环或 pcntl_signal
        $this->logInfo('Signal watcher skipped (Windows/Dev mode)');
    }

    private function gracefulShutdown(): void
    {
        $this->logInfo('Shutdown requested, initiating graceful shutdown...');
        $this->running = false;

        if ($this->taskChannel) {
            try {
                $this->taskChannel->close();
            } catch (\Throwable) {
            }
        }

        $maxWait = 30;
        $start = time();
        while ($this->activeCoroutines > 0 && (time() - $start) < $maxWait) {
            $this->logInfo("Waiting for {$this->activeCoroutines} coroutine(s) to finish...");
            usleep(500000);
        }

        if ($this->activeCoroutines > 0) {
            $this->logWarning("Force shutdown with {$this->activeCoroutines} active coroutine(s)");
        }

        $this->releaseAllLocks();
        Cache::forget("scheduler:heartbeat:{$this->workerId}");

        $this->logInfo('Shutdown complete');
    }

    private function releaseAllLocks(): void
    {
        if (empty($this->acquiredLocks)) {
            return;
        }

        foreach ($this->acquiredLocks as $taskId => $item) {
            if ($item['type'] === 'pg' && $item['lockKey'] > 0) {
                try {
                    DB::selectOne('SELECT pg_advisory_unlock(?)', [$item['lockKey']]);
                } catch (\Throwable) {
                }
            } elseif ($item['type'] === 'cache') {
                try {
                    /** @var \Illuminate\Cache\Lock $cacheLock */
                    $cacheLock = $item['cacheLock'];
                    $cacheLock->release();
                } catch (\Throwable) {
                }
            }
        }

        $this->acquiredLocks = [];
        $this->logInfo('All advisory locks released');
    }

    /* ==================== 调度循环 ==================== */

    private function scheduleLoop(): void
    {
        $this->logInfo("Schedule loop started");

        while ($this->running) {
            try {
                $startTime = microtime(true);

                $this->dispatchDueTasks();

                $elapsed = (microtime(true) - $startTime) * 1000000;
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
                usleep(1000000);
            }
        }

        $this->logInfo("Schedule loop stopped");
    }

    private function dispatchDueTasks(): void
    {
        $fired = $this->timingWheel->advance((int) microtime(true));

        foreach ($fired as $entry) {
            $this->taskChannel->push($entry->toPayload());
            $this->stats['tasks_executed']++;
        }
    }

    /**
     * 热重载秒级任务。
     * 新增 → schedule，interval 变化 → cancel + re-schedule，已删除 → cancel。
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
        $freshIds = [];

        foreach ($tasks as $task) {
            $freshIds[] = $task->id;
            $entry = TaskEntry::fromArray($task->toArray());

            if (!$this->timingWheel->has($task->id)) {
                $this->timingWheel->schedule($entry, $nowTs);
                $this->logInfo("Task [{$task->id}] registered (new, interval={$entry->intervalSeconds}s)");
            } else {
                $registered = $this->timingWheel->getEntry($task->id);
                if ($registered && $registered->intervalSeconds !== $entry->intervalSeconds) {
                    $oldInterval = $registered->intervalSeconds;
                    $this->timingWheel->cancel($task->id);
                    $this->timingWheel->schedule($entry, $nowTs);
                    $this->logInfo(sprintf(
                        'Task [%d] re-registered (interval changed: %ds → %ds)',
                        $task->id,
                        $oldInterval,
                        $entry->intervalSeconds
                    ));
                }
            }
        }

        // 取消已停用/删除的任务
        foreach ($this->timingWheel->ids() as $registeredId) {
            if (!in_array($registeredId, $freshIds, true)) {
                $this->timingWheel->cancel($registeredId);
                $this->logInfo("Task [{$registeredId}] cancelled (disabled/deleted)");
            }
        }

        $stats = $this->timingWheel->stats();
        $this->logInfo(sprintf(
            'Second-level tasks reloaded: active=%d wheel_registered=%d',
            count($tasks),
            $stats['registered']
        ));
    }

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
            if ($task->without_overlapping) {
                if (!$this->tryAcquireLock($task)) {
                    continue;
                }
            }

            $this->taskChannel->push($task->toArray());

            $task->update([
                'last_run_at' => now(),
                'next_run_at' => $task->calculateNextRun(),
            ]);

            $this->stats['tasks_executed']++;
        }

        foreach ($tasks as $task) {
            if (!$this->timingWheel->has($task->id)) {
                $this->timingWheel->schedule(
                    TaskEntry::fromArray($task->toArray()),
                    $nowTs
                );
            }
        }
    }

    /* ==================== 锁 ==================== */

    private function tryAcquireLock(TaskSchedule $task): bool
    {
        $lockNamespace = Config::get('schedule.locking.namespace', 0x5441534B);
        $lockKey = ($lockNamespace << 32) | ($task->id & 0xFFFFFFFF);

        try {
            $result = DB::selectOne(
                'SELECT pg_try_advisory_lock(?) as acquired',
                [$lockKey]
            );

            if ($result && $result->acquired) {
                $this->acquiredLocks[$task->id] = [
                    'taskId' => $task->id,
                    'lockKey' => $lockKey,
                    'type' => 'pg',
                ];
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            // PG 不可用，fallback 到 Cache 锁
            $cacheLock = Cache::lock(
                "task_schedule:{$task->id}",
                ($task->overlap_release_minutes ?? 5) * 60
            );

            if ($cacheLock->get()) {
                $this->acquiredLocks[$task->id] = [
                    'taskId' => $task->id,
                    'lockKey' => 0,
                    'type' => 'cache',
                    'cacheLock' => $cacheLock,
                ];
                return true;
            }
            return false;
        }
    }

    private function acquireExecutionLock(int $taskId, string $dispatchId): bool
    {
        // 已在 dispatchViaOrm() 里通过 tryAcquireLock() 获取，直接确认
        if (isset($this->acquiredLocks[$taskId])) {
            return true;
        }

        // 未获取则补获取（针对 timing wheel 触发等不经过 dispatchViaOrm 的路径）
        $lockNamespace = Config::get('schedule.locking.namespace', 0x5441534B);
        $lockKey = ($lockNamespace << 32) | ($taskId & 0xFFFFFFFF);

        try {
            $result = DB::selectOne(
                'SELECT pg_try_advisory_lock(?) as acquired',
                [$lockKey]
            );

            if ($result && $result->acquired) {
                $this->acquiredLocks[$taskId] = [
                    'taskId' => $taskId,
                    'lockKey' => $lockKey,
                    'type' => 'pg',
                ];
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->logError("Failed to acquire execution lock | Task: {$taskId} | Error: " . $e->getMessage());
            return false;
        }
    }

    private function releaseExecutionLock(int $taskId): void
    {
        if (!isset($this->acquiredLocks[$taskId])) {
            return;
        }

        $item = $this->acquiredLocks[$taskId];

        if ($item['type'] === 'pg' && $item['lockKey'] > 0) {
            try {
                DB::selectOne('SELECT pg_advisory_unlock(?)', [$item['lockKey']]);
            } catch (\Throwable) {
            }
        } elseif ($item['type'] === 'cache') {
            try {
                /** @var \Illuminate\Cache\Lock $cacheLock */
                $cacheLock = $item['cacheLock'];
                $cacheLock->release();
            } catch (\Throwable) {
            }
        }

        unset($this->acquiredLocks[$taskId]);
        $this->logInfo("Execution lock released | Task: {$taskId}");

        // 兼容原存储过程（如果数据库里还有这个函数）
        try {
            DB::statement('SELECT release_task_lock(?, ?)', [
                $taskId,
                TaskState::SUCCESS->value,
            ]);
        } catch (\Throwable) {
        }
    }

    /* ==================== Worker ==================== */

    private function workerLoop(): void
    {
        while ($this->running) {
            try {
                $taskData = $this->taskChannel->pop();
                if ($taskData === null) {
                    break;
                }

                $this->activeCoroutines++;
                Coroutine::run(function () use ($taskData) {
                    try {
                        // ✅ 关键：每个任务协程用独立 DB 连接
                        DB::purge('pgsql');

                        $this->executeTask($taskData);
                    } catch (\Throwable $e) {
                        $this->logError("Task execution error: " . $e->getMessage());
                    } finally {
                        // 用完释放连接回连接池
                        DB::purge('pgsql');
                        $this->activeCoroutines--;
                    }
                });
            } catch (\Throwable $e) {
                $this->logError("Worker loop error: " . $e->getMessage());
                usleep(100000);
            }
        }
    }

    private function executeTask(array $taskData): void
    {
        $taskId = $taskData['id'] ?? 0;
        $command = $taskData['command'] ?? '';
        $taskType = $taskData['task_type'] ?? 'command';
        $dispatchId = uniqid('disp_', true);

        $startTime = microtime(true);
        $this->logInfo("Executing task [ID:{$taskId}] type={$taskType} cmd={$command}");

        $run = $this->createRunRecord($taskData, $dispatchId);

        try {
            // 防重叠检查
            if (($taskData['without_overlapping'] ?? false)) {
                if (!isset($this->acquiredLocks[$taskId]) && !$this->acquireExecutionLock($taskId, $dispatchId)) {
                    $this->skipRunRecord($run, $taskData['mutex_name'] ?? null);
                    $this->stats['tasks_skipped']++;
                    return;
                }
            }

            $exitCode = match ($taskType) {
                'command' => $this->executeCommand($taskData),
                'job'     => $this->dispatchJob($taskData),
                'url'     => $this->callUrl($taskData),
                'shell'   => $this->executeShell($taskData),
                'closure' => $this->executeClosure($taskData),
                default   => throw new \InvalidArgumentException("Unknown task type: {$taskType}"),
            };

            $duration = round(microtime(true) - $startTime, 4);
            $this->finishRunRecord($run, $exitCode, $duration);

            if ($exitCode === 0) {
                $this->stats['tasks_succeeded']++;
            } else {
                $this->stats['tasks_failed']++;
            }
            $this->stats['total_duration'] += $duration;

            // 慢任务告警
            $slowThreshold = Config::get('schedule.monitoring.slow_task_threshold', 30);
            if ($duration > $slowThreshold) {
                $this->logWarning("Slow task detected: {$command} ({$duration}s)");
            }

        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 4);

            // 异常时也通过 finishRunRecord 标记失败
            $this->finishRunRecord($run, 1, $duration, $e->getMessage());

            $this->stats['tasks_failed']++;
            $this->logError("Task [ID:{$taskId}] exception: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } finally {
            // 释放锁（如果持有）
            if (isset($this->acquiredLocks[$taskId])) {
                $this->releaseExecutionLock($taskId);
            }
        }
    }

    /* ==================== 执行器 ==================== */

    private function executeCommand(array $task): int
    {
        $command = trim($task['command'] ?? '');
        $parameters = $task['parameters'] ?? null;

        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $params = [];
        if ($parameters) {
            $params = is_array($parameters) || is_object($parameters)
                ? (array) $parameters
                : preg_split('/\s+/', trim($parameters));
        }

        if ($task['in_background'] ?? false) {
            $this->runInBackground($command, $params);
            return 0;
        }

        return $this->runForeground($command, $params, $task);
    }

    private function runForeground(string $command, array $params, array $task): int
    {
        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

        if (!empty($params)) {
            $fullCommand .= ' ' . implode(' ', array_map('escapeshellarg', $params));
        }

        $timeout = $task['max_runtime'] ?? 0;
        if ($timeout > 0) {
            $fullCommand = sprintf('timeout %d %s', $timeout, $fullCommand);
        }

        if (!empty($task['output_file_path'])) {
            $outputPath = Config::get('schedule.output.path') . '/' . $task['output_file_path'];
            $append = $task['output_append'] ? '>>' : '>';
            $fullCommand .= " {$append} {$outputPath} 2>&1";
        }

        $exitCode = 0;
        system($fullCommand, $exitCode);

        return $exitCode;
    }

    private function runInBackground(string $command, array $params): void
    {
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $fullCommand = sprintf(
            '%s artisan %s > %s 2>&1 &',
            PHP_BINARY,
            $command . (!empty($params) ? ' ' . implode(' ', array_map('escapeshellarg', $params)) : ''),
            $nullDevice
        );

        exec($fullCommand);
    }

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

    private function executeClosure(array $task): int
    {
        throw new \RuntimeException("Closure execution not supported in database-driven scheduler");
    }

    /* ==================== Run 记录 ==================== */

    private function createRunRecord(array $taskData, string $dispatchId): TaskScheduleRun
    {
        return TaskScheduleRun::create([
            'task_id'     => $taskData['id'] ?? null,
            'event_name'  => $taskData['command'] ?? 'manual',  // 命令即事件名
            'command'     => $taskData['command'] ?? null,
            'expression'  => $taskData['expression'] ?? null,
            'timezone'    => $taskData['timezone'] ?? null,
            'state'       => TaskState::RUNNING->value,
            'started_at'  => now(),
        ]);
    }

    /**
     * 任务完成后更新 run 记录
     */
    private function finishRunRecord(
        TaskScheduleRun $run,
        int $exitCode,
        float $duration,
        ?string $output = null
    ): void {
        $state = $exitCode === 0 ? TaskState::SUCCESS : TaskState::FAILED;

        $run->update([
            'state'         => $state->value,
            'exit_code'     => $exitCode,
            'duration'      => $duration,
            'output'        => $output !== null ? mb_substr($output, 0, 65535) : null,
            'finished_at'   => now(),
        ]);
    }

    /**
     * 因重叠跳过时更新 run 记录
     */
    private function skipRunRecord(TaskScheduleRun $run, ?string $mutexName = null): void
    {
        $run->update([
            'state'       => TaskState::SKIPPED->value,
            'mutex_name'  => $mutexName,
            'skipped_because_overlapping' => true,
            'finished_at' => now(),
        ]);
    }

    /* ==================== 心跳 / 指标 / 版本 ==================== */

    private function heartbeatLoop(): void
    {
        $interval = Config::get('schedule.precision.heartbeat_interval', 30);

        while ($this->running) {
            usleep($interval * 1000000);
            $this->lastHeartbeat = microtime(true);
            $this->reportHeartbeat();
            $this->cleanupStaleRecords();
        }
    }

    private function reportHeartbeat(): void
    {
        $data = [
            'worker_id' => $this->workerId,
            'server_id' => $this->serverId,
            'timestamp' => now()->toIso8601String(),
            'active_coroutines' => $this->activeCoroutines,
            'stats' => $this->stats,
        ];

        Cache::put(
            "scheduler:heartbeat:{$this->workerId}",
            $data,
            now()->addMinutes(5)
        );
    }

    private function cleanupStaleRecords(): void
    {
        try {
            DB::statement('SELECT cleanup_dispatch_records(?)', [24]);
        } catch (\Throwable) {
        }
    }

    private function metricsLoop(): void
    {
        $interval = 60;

        while ($this->running) {
            usleep($interval * 1000000);

            $prefix = Config::get('schedule.monitoring.metrics_prefix', 'task_schedule');
            $avg = round($this->stats['total_duration'] / max($this->stats['tasks_executed'], 1), 4);

            $this->logInfo("Metrics | {$prefix}_tasks_total={$this->stats['tasks_executed']} " .
                "| success={$this->stats['tasks_succeeded']} " .
                "| failed={$this->stats['tasks_failed']} " .
                "| skipped={$this->stats['tasks_skipped']} " .
                "| avg_duration={$avg}s " .
                "| active_coroutines={$this->activeCoroutines}");
        }
    }

    /* ==================== 主循环 ==================== */

    private function mainLoop(): void
    {
        $lastVersionCheck = 0;
        $lastFullReload = 0;
        $lastDump = 0;

        while ($this->running) {
            $now = time();

            // 调试用：每 10 秒 dump 一次时间轮快照
            if ($now - $lastDump >= 10) {
                $lastDump = $now;
                $snap = $this->timingWheel->snapshot();
                $slots = [];
                foreach ($snap as $e) {
                    $slots[] = sprintf('id=%d interval=%d nextFire=%d', $e->taskId, $e->intervalSeconds, $e->nextFireAt);
                }
                $this->logInfo('WHEEL DUMP: ' . implode(' | ', $slots));
            }

            if (microtime(true) - $this->lastHeartbeat > 300) {
                $this->logWarning("Heartbeat timeout detected");
                $this->lastHeartbeat = microtime(true);
            }

            // ① version 变化检测（秒级）
            if ($now - $lastVersionCheck >= 1) {
                $lastVersionCheck = $now;
                $current = $this->currentVersion();
                if ($current !== $this->knownVersion) {
                    $this->logInfo("Task version changed: {$this->knownVersion} -> {$current}, reloading");
                    $this->knownVersion = $current;
                    $this->reloadSecondLevelTasks();
                }
            }

            // ② 30 秒强制全量 reload（兜底，不依赖 version）
            if ($now - $lastFullReload >= 30) {
                $lastFullReload = $now;
                $this->logInfo('Periodic full reload (30s)');
                $this->reloadSecondLevelTasks();
            }

            usleep(100000);
        }

        $this->waitForCompletion();
    }

    private function currentVersion(): int
    {
        try {
            return (int) (TaskSchedule::query()->max('version') ?? 0);
        } catch (\Throwable) {
            return $this->knownVersion;
        }
    }

    private function waitForCompletion(): void
    {
        $timeout = 30;
        $start = time();

        while ($this->activeCoroutines > 0 && (time() - $start) < $timeout) {
            usleep(100000);
        }

        if ($this->activeCoroutines > 0) {
            $this->logWarning("Force shutdown with {$this->activeCoroutines} active coroutines");
        }
    }

    /* ==================== 日志 ==================== */

    private function logInfo(string $message): void
    {
        echo sprintf("[%s] [Scheduler:%s] %s\n", date('Y-m-d H:i:s'), $this->workerId, $message);
        Log::channel('stack')->info($message, ['worker' => $this->workerId]);
    }

    private function logError(string $message, array $context = []): void
    {
        echo sprintf("[%s] [Scheduler:%s] ERROR: %s\n", date('Y-m-d H:i:s'), $this->workerId, $message);
        Log::channel('stack')->error($message, array_merge($context, ['worker' => $this->workerId]));
    }

    private function logWarning(string $message): void
    {
        echo sprintf("[%s] [Scheduler:%s] WARN: %s\n", date('Y-m-d H:i:s'), $this->workerId, $message);
        Log::channel('stack')->warning($message, ['worker' => $this->workerId]);
    }
}
