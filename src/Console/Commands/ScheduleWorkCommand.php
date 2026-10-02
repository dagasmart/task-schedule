<?php

namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use DagaSmart\TaskSchedule\Models\TaskSchedule;

/**
 * 秒级调度工作命令
 *
 * 持续运行，每秒检查并执行到期的秒级任务
 * 用于需要秒/分级精度的场景
 */
class ScheduleWorkCommand extends Command
{
    protected $signature = 'schedule:work
                            {--interval=1 : 检查间隔(秒)}
                            {--precision=1 : 精度级别(1=秒 2=分)}
                            {--max-runtime=3600 : 最大运行时间(秒)}';

    protected $description = '持续运行的高精度调度器（秒/分级）';

    private bool $running = true;
    private int $executedCount = 0;
    private int $failedCount = 0;

    public function handle(): int
    {
        $interval = (int) $this->option('interval');
        $precision = (int) $this->option('precision');
        $maxRuntime = (int) $this->option('max-runtime');

        // 注册信号处理
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        $this->info("Starting high-precision scheduler...");
        $this->info("Interval: {$interval}s | Precision: {$precision} | Max runtime: {$maxRuntime}s");
        $this->newLine();

        $startTime = time();
        $lastReport = $startTime;

        while ($this->running) {
            $tickStart = microtime(true);

            try {
                $this->tick($precision);
            } catch (\Throwable $e) {
                $this->error("Tick error: " . $e->getMessage());
                $this->failedCount++;
            }

            // 信号处理
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // 检查最大运行时间
            if (time() - $startTime > $maxRuntime) {
                $this->info("Max runtime reached, shutting down...");
                break;
            }

            // 每分钟报告一次状态
            if (time() - $lastReport >= 60) {
                $this->line(sprintf(
                    "[%s] Executed: %d | Failed: %d | Uptime: %s",
                    now()->format('H:i:s'),
                    $this->executedCount,
                    $this->failedCount,
                    gmdate('H:i:s', time() - $startTime)
                ));
                $lastReport = time();
            }

            // 计算 sleep 时间
            $tickDuration = microtime(true) - $tickStart;
            $sleepTime = max(0, $interval - $tickDuration);

            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }
        }

        $this->info("Scheduler stopped. Total executed: {$this->executedCount}");
        return self::SUCCESS;
    }

    /**
     * 单次 tick：检查并执行到期任务
     */
    private function tick(int $precision): void
    {
        $now = now();

        // 获取到期任务
        $tasks = TaskSchedule::query()
            ->active()
            ->where('precision', $precision)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->byPriority()
            ->limit(20)
            ->get();

        foreach ($tasks as $task) {
            // 防重叠
            if ($task->without_overlapping) {
                $lockKey = "task_lock:{$task->id}";
                if (!Cache::add($lockKey, time(), $task->overlap_release_minutes * 60)) {
                    continue;
                }
            }

            // 执行
            $this->executeTask($task);

            // 更新下次执行时间
            $task->update([
                'last_run_at' => $now,
                'next_run_at' => $task->calculateNextRun(),
            ]);

            $this->executedCount++;
        }
    }

    /**
     * 执行单个任务
     */
    private function executeTask(TaskSchedule $task): void
    {
        $start = microtime(true);

        try {
            $exitCode = $this->dispatchTask($task);
            $duration = round(microtime(true) - $start, 4);

            $this->recordLog($task, $exitCode, $duration);

            if ($exitCode !== 0) {
                $this->failedCount++;
            }
        } catch (\Throwable $e) {
            $this->failedCount++;
            $this->recordLog($task, 1, round(microtime(true) - $start, 4), $e->getMessage());
        }
    }

    /**
     * 派发任务
     */
    private function dispatchTask(TaskSchedule $task): int
    {
        // 根据任务类型执行
        return match ($task->task_type) {
            'command' => $this->executeCommand($task),
            'job' => $this->dispatchJob($task),
            'url' => $this->callUrl($task),
            default => $this->executeCommand($task),
        };
    }

    /**
     * 执行命令
     */
    private function executeCommand(TaskSchedule $task): int
    {
        $command = trim($task->command);
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $params = [];
        if (!empty($task->parameters)) {
            $params = is_array($task->parameters)
                ? $task->parameters
                : preg_split('/\s+/', trim($task->parameters));
        }

        // 后台运行
        if ($task->in_background) {
            $this->runInBackground($task);
            return 0;
        }

        // 同步执行
        return $this->runSync($command, $params, $task);
    }

    /**
     * 同步执行
     */
    private function runSync(string $command, array $params, TaskSchedule $task): int
    {
        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);
        if (!empty($params)) {
            $fullCommand .= ' ' . implode(' ', array_map('escapeshellarg', $params));
        }

        if ($task->max_runtime > 0) {
            $fullCommand = sprintf('timeout %d %s', $task->max_runtime, $fullCommand);
        }

        $output = [];
        $exitCode = 0;
        exec($fullCommand, $output, $exitCode);

        return $exitCode;
    }

    /**
     * 后台运行
     */
    private function runInBackground(TaskSchedule $task): void
    {
        $command = $this->buildCommand($task);
        exec(sprintf('nohup %s > /dev/null 2>&1 &', $command));
    }

    /**
     * 构建命令
     */
    private function buildCommand(TaskSchedule $task): string
    {
        $command = trim($task->command);
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, escapeshellarg($command));

        if (!empty($task->parameters)) {
            $params = is_array($task->parameters) ? $task->parameters : explode(' ', $task->parameters);
            foreach ($params as $param) {
                $fullCommand .= ' ' . escapeshellarg($param);
            }
        }

        return $fullCommand;
    }

    /**
     * 派发队列任务
     */
    private function dispatchJob(TaskSchedule $task): int
    {
        $jobClass = $task->command;
        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Job class not found: {$jobClass}");
        }

        $params = (array) ($task->parameters ?? []);
        dispatch(new $jobClass(...$params));

        return 0;
    }

    /**
     * 调用 URL
     */
    private function callUrl(TaskSchedule $task): int
    {
        $url = $task->command;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $task->max_runtime ?? 30);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300 ? 0 : 1;
    }

    /**
     * 记录日志
     */
    private function recordLog(TaskSchedule $task, int $exitCode, float $duration, ?string $error = null): void
    {
        DB::table('task_schedule_log')->insert([
            'task_id' => $task->id,
            'task_name' => $task->task_name,
            'command' => $task->command,
            'status' => $exitCode === 0 ? 2 : 3,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'output' => $error ? json_encode(['error' => $error]) : null,
            'started_at' => now()->subSeconds((int) $duration),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 关闭调度器
     */
    public function shutdown(): void
    {
        $this->running = false;
        $this->info("\nShutting down scheduler...");
    }
}
