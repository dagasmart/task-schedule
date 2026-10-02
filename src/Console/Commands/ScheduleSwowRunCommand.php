<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use DagaSmart\TaskSchedule\Console\SwowScheduler;

class ScheduleSwowRunCommand extends Command
{
    protected $signature = 'schedule:swow-run
                            {--daemon : Run as daemon (Unix only)}
                            {--max-concurrency=1024 : Max coroutines}
                            {--tick-ms=10 : Tick interval in ms}
                            {--workers=1 : Number of workers}';

    protected $description = 'Start the Swow-based task scheduler (cross-platform, no pcntl required)';

    public function handle(): int
    {
        // Windows 下不允许 daemon 模式
        if ($this->option('daemon') && DIRECTORY_SEPARATOR === '\\') {
            $this->error('Daemon 模式在 Windows 下不支持（需要 pcntl/posix 扩展）。');
            $this->line('请直接运行：php artisan schedule:swow-run');
            return self::FAILURE;
        }

        $this->configureRuntime();

        if ($this->option('daemon')) {
            $this->runAsDaemon();
            return self::SUCCESS;
        }

        $this->runForeground();
        return self::SUCCESS;
    }

    /**
     * 配置运行时参数
     */
    private function configureRuntime(): void
    {
        // 设置协程并发数
        $maxConcurrency = (int) $this->option('max-concurrency');
        Config::set('schedule.swow.max_coroutines', $maxConcurrency);

        // 设置 tick 间隔
        $tickMs = (int) $this->option('tick-ms');
        Config::set('schedule.swow.loop_tick_ms', $tickMs);

        // 设置 Worker 数
        $workers = (int) $this->option('workers');
        Config::set('schedule.precision.workers', $workers);

        // 信号异步处理（仅 Unix + 有 pcntl 时，SwowScheduler 内部用 Signal::wait 兜底）
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // 设置最大执行时间
        set_time_limit(0);
        ini_set('memory_limit', '512M');
    }

    /**
     * 守护进程模式（仅 Unix/Linux/macOS）
     */
    private function runAsDaemon(): void
    {
        $pidFile = storage_path('logs/scheduler.pid');

        // 检查是否已在运行
        if (file_exists($pidFile)) {
            $oldPid = (int) file_get_contents($pidFile);
            if ($oldPid > 0 && function_exists('posix_kill') && posix_kill($oldPid, 0)) {
                $this->error("调度器已在运行 (PID: {$oldPid})");
                return;
            }
            @unlink($pidFile);
        }

        // Fork 子进程
        if (!function_exists('pcntl_fork')) {
            $this->error('pcntl_fork 不可用，无法以守护进程模式运行');
            return;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->error('无法创建守护进程');
            return;
        }

        if ($pid > 0) {
            // 父进程退出
            file_put_contents($pidFile, $pid);
            $this->info("调度器已启动 (PID: {$pid})");
            return;
        }

        // 子进程继续运行
        if (function_exists('posix_setsid')) {
            posix_setsid();
        }

        // 重定向标准输入输出
        if (is_resource(STDIN)) { fclose(STDIN); }
        if (is_resource(STDOUT)) { fclose(STDOUT); }
        if (is_resource(STDERR)) { fclose(STDERR); }

        $this->runForeground();
    }

    /**
     * 前台运行
     */
    private function runForeground(): void
    {
        $this->info('Starting Swow Scheduler...');
        $this->info('Press Ctrl+C to stop gracefully');

        $scheduler = new SwowScheduler();
        $scheduler->run();
    }
}
