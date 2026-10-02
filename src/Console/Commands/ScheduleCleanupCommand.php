<?php

namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleCleanupCommand extends Command
{
    protected $signature = 'schedule:cleanup
                            {--days=30 : 日志保留天数}
                            {--batch=1000 : 每批删除数量}
                            {--optimize : 是否优化表}';

    protected $description = '清理过期的调度日志和僵尸分发记录';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $batch = (int) $this->option('batch');

        $this->info("Starting cleanup (retention: {$days} days, batch: {$batch})");

        // 清理日志
        $this->cleanupLogs($days, $batch);

        // 清理僵尸分发记录
        $this->cleanupStaleDispatches();

        // 优化表
        if ($this->option('optimize')) {
            $this->optimizeTables();
        }

        $this->info('Cleanup completed successfully.');
        return self::SUCCESS;
    }

    /**
     * 清理过期日志
     */
    private function cleanupLogs(int $days, int $batch): void
    {
        $table = config('schedule.log', 'task_schedule_log');
        $cutoff = now()->subDays($days);

        $this->line("Cleaning logs older than {$cutoff->toDateTimeString()}...");

        $totalDeleted = 0;

        do {
            $deleted = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->line("  Deleted {$deleted} records (total: {$totalDeleted})");
            }

            // 让出 CPU
            usleep(10000);
        } while ($deleted > 0);

        $this->info("Total logs cleaned: {$totalDeleted}");
    }

    /**
     * 清理僵尸分发记录
     */
    private function cleanupStaleDispatches(): void
    {
        $table = config('schedule.dispatch', 'task_schedule_dispatch');

        if (!Schema::hasTable($table)) {
            return;
        }

        // 清理超过1小时未完成的记录
        $cleaned = DB::table($table)
            ->whereIn('status', [0, 1])
            ->where('started_at', '<', now()->subHour())
            ->update([
                'status' => 3, // FAILED
                'finished_at' => now(),
            ]);

        if ($cleaned > 0) {
            $this->warn("Reset {$cleaned} stale dispatch record(s)");
        }

        // 清理已完成超过24小时的记录
        $deleted = DB::table($table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDay())
            ->delete();

        if ($deleted > 0) {
            $this->line("Deleted {$deleted} old dispatch record(s)");
        }
    }

    /**
     * 优化表
     */
    private function optimizeTables(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $tables = [
                config('schedule.table', 'task_schedule'),
                config('schedule.log', 'task_schedule_log'),
                config('schedule.dispatch', 'task_schedule_dispatch'),
            ];

            foreach ($tables as $table) {
                $this->line("Analyzing table: {$table}");
                DB::statement("ANALYZE {$table}");
            }
        }
    }
}
