<?php

namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use DagaSmart\TaskSchedule\Console\SwowScheduler;

class ScheduleSwowRunCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'schedule:swow-run
                            {--daemon : 以守护进程模式运行}
                            {--workers=1 : Worker 数量}
                            {--max-concurrency=1024 : 最大协程并发数}
                            {--tick-ms=10 : 事件循环 tick 间隔(毫秒)}';

    /**
     * The console command description.
     */
    protected $description = '启动 Swow 协程调度器（支持秒/分/时/天/周/月级精度）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 检查 Swow 扩展
        if (!extension_loaded('swow')) {
            $this->error('Swow 扩展未安装。请先安装：https://github.com/swow/swow');
            $this->info('或者使用传统调度器：php artisan schedule:run');
            return self::FAILURE;
        }

        // 设置运行参数
        $this->configureRuntime();

        $this->info('┌─────────────────────────────────────────────────────────┐');
        $this->info('│       Swow Task Scheduler - High Performance Engine     │');
        $this->info('├─────────────────────────────────────────────────────────┤');
        $this->info('│  Precision : Second / Minute / Hour / Day / Week / Month│');
        $this->info('│  Engine    : Swow Coroutine + PostgreSQL Advisory Lock  │');
        $this->info('│  Mode      : ' . str_pad($this->option('daemon') ? 'Daemon' : 'Foreground', 42) . '│');
        $this->info('│  Workers   : ' . str_pad($this->option('workers'), 42) . '│');
        $this->info('│  Concurrency: ' . str_pad($this->option('max-concurrency'), 41) . '│');
        $this->info('│  Tick      : ' . str_pad($this->option('tick-ms') . 'ms', 42) . '│');
        $this->info('└─────────────────────────────────────────────────────────┘');

        if ($this->option('daemon')) {
            $this->runAsDaemon();
        } else {
            $this->runForeground();
        }

        return self::SUCCESS;
    }

    /**
     * 配置运行时参数
     */
    private function configureRuntime(): void
    {
        // 设置协程并发数
        $maxConcurrency = (int) $this->option('max-concurrency');
        config(['schedule.swow.max_coroutines' => $maxConcurrency]);

        // 设置 tick 间隔
        $tickMs = (int) $this->option('tick-ms');
        config(['schedule.swow.loop_tick_ms' => $tickMs]);

        // 设置 Worker 数
        $workers = (int) $this->option('workers');
        config(['schedule.precision.workers' => $workers]);

        // 忽略用户中止
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // 设置最大执行时间
        set_time_limit(0);
        ini_set('memory_limit', '512M');
    }

    /**
     * 前台运行
     */
    private function runForeground(): void
    {
        $scheduler = new SwowScheduler();
        $scheduler->run();
    }

    /**
     * 守护进程模式
     */
    private function runAsDaemon(): void
    {
        $pidFile = storage_path('logs/scheduler.pid');

        // 检查是否已在运行
        if (file_exists($pidFile)) {
            $oldPid = (int) file_get_contents($pidFile);
            if ($oldPid > 0 && posix_kill($oldPid, 0)) {
                $this->error("调度器已在运行 (PID: {$oldPid})");
                return;
            }
            unlink($pidFile);
        }

        // Fork 子进程
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
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $this->runForeground();
    }
}
