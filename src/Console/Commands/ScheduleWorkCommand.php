<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use DagaSmart\TaskSchedule\Models\TaskSchedule;

class ScheduleWorkCommand extends Command
{
    protected $signature = 'schedule:work
                            {--interval=60 : Run interval in seconds}
                            {--precision=1 : Precision level (1=second, 2=minute)}
                            {--memory=128 : Memory limit in MB}
                            {--stop-on-failure : Stop on first failure}';

    protected $description = 'Run the task scheduler worker loop (cross-platform)';

    private bool $running = true;
    private int $iterations = 0;
    private array $stats = [
        'executed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    public function handle(): int
    {
        // 信号处理（仅 Unix + 有 pcntl 时）
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        $interval = (int) $this->option('interval');
        $memoryLimit = (int) $this->option('memory');

        ini_set('memory_limit', "{$memoryLimit}M");
        set_time_limit(0);

        $this->info("Schedule worker started (interval: {$interval}s, memory: {$memoryLimit}M)");
        $this->info('Press Ctrl+C to stop');

        while ($this->running) {
            $this->tick();

            // 信号处理
            if (DIRECTORY_SEPARATOR !== '\\' && function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $this->iterations++;
            sleep($interval);
        }

        $this->info('Schedule worker stopped');
        return self::SUCCESS;
    }

    /**
     * 单次调度执行
     */
    private function tick(): void
    {
        try {
            $tasks = TaskSchedule::query()
                ->active()
                ->where(function ($q) {
                    $q->whereNull('next_run_at')
                        ->orWhere('next_run_at', '<=', now());
                })
                ->orderBy('priority', 'desc')
                ->orderBy('next_run_at', 'asc')
                ->limit(100)
                ->get();

            foreach ($tasks as $task) {
                $this->processTask($task);
            }

            if ($this->iterations % 10 === 0) {
                $this->logStats();
            }
        } catch (\Throwable $e) {
            $this->error("Tick error: " . $e->getMessage());
        }
    }

    /**
     * 处理单个任务
     */
    private function processTask(TaskSchedule $task): void
    {
        // 防重叠
        if ($task->without_overlapping) {
            $ttl = 300; // 默认 5 分钟
            if (isset($task->overlap_release_minutes)) {
                $ttl = $task->overlap_release_minutes * 60;
            } elseif (method_exists($task, 'getOverlapReleaseMinutes')) {
                $ttl = $task->getOverlapReleaseMinutes() * 60;
            }
            $lockKey = "task_lock:{$task->id}";
            if (!Cache::add($lockKey, time(), $ttl)) {
                $this->stats['skipped']++;
                return;
            }
        }

        $this->stats['executed']++;

        try {
            $exitCode = $this->executeTask($task);

            if ($exitCode === 0) {
                $this->stats['succeeded']++;
            } else {
                $this->stats['failed']++;
            }

            // 更新任务运行时间
            $task->update([
                'last_run_at' => now(),
                'next_run_at' => $task->calculateNextRun(),
            ]);

            $this->info("Task [{$task->id}] completed with exit code {$exitCode}");
        } catch (\Throwable $e) {
            $this->stats['failed']++;
            $this->error("Task [{$task->id}] failed: " . $e->getMessage());

            if ($this->option('stop-on-failure')) {
                $this->shutdown();
            }
        } finally {
            if ($task->without_overlapping) {
                Cache::forget("task_lock:{$task->id}");
            }
        }
    }

    /**
     * 执行任务
     */
    private function executeTask(TaskSchedule $task): int
    {
        $command = trim($task->command ?? '');
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

        $timeout = $task->max_runtime ?? 0;
        if ($timeout > 0) {
            $fullCommand = sprintf('timeout %d %s', $timeout, $fullCommand);
        }

        if (!empty($task->output_file_path)) {
            $outputPath = Config::get('schedule.output.path', storage_path('logs/schedule')) . '/' . $task->output_file_path;
            $append = $task->output_append ? '>>' : '>';
            $fullCommand .= " {$append} {$outputPath} 2>&1";
        }

        $exitCode = 0;
        system($fullCommand, $exitCode);

        return $exitCode;
    }

    /**
     * 优雅退出
     */
    public function shutdown(): void
    {
        $this->info('Shutting down gracefully...');
        $this->running = false;
        $this->logStats();
    }

    /**
     * 输出统计
     */
    private function logStats(): void
    {
        $this->line(sprintf(
            '[Stats] iterations=%d executed=%d succeeded=%d failed=%d skipped=%d',
            $this->iterations,
            $this->stats['executed'],
            $this->stats['succeeded'],
            $this->stats['failed'],
            $this->stats['skipped']
        ));
    }
}
