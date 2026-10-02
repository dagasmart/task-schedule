<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

class ScheduleCleanupCommand extends Command
{
    protected $signature = 'schedule:cleanup
                            {--days=30 : Days to retain}
                            {--batch=1000 : Batch size}
                            {--force : Skip confirmation}';

    protected $description = 'Clean up old task schedule run and log records';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $batch = (int) $this->option('batch');

        if (!$this->option('force') && !$this->confirm("Clean up records older than {$days} days?")) {
            $this->info('Aborted');
            return self::SUCCESS;
        }

        $this->info("Cleaning up records older than {$days} days...");

        // 清理 task_schedule_run
        $runCount = $this->cleanupTable(TaskScheduleRun::class, $days, $batch);
        $this->info("Cleaned {$runCount} run records");

        // 清理 task_schedule_log
        $logCount = $this->cleanupTable(TaskScheduleLog::class, $days, $batch);
        $this->info("Cleaned {$logCount} log records");

        // 调用存储过程（如果存在）
        try {
            DB::statement('SELECT cleanup_dispatch_records(?)', [$days]);
            $this->info('Stored procedure cleanup_dispatch_records executed');
        } catch (\Throwable $e) {
            // 存储过程不存在或失败，忽略
        }

        $this->info('Cleanup completed');
        return self::SUCCESS;
    }

    /**
     * 清理指定表
     */
    private function cleanupTable(string $modelClass, int $days, int $batch): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $deleted = $modelClass::query()
                ->where('created_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->line("  Deleted {$deleted} records (total: {$totalDeleted})");
            }
        } while ($deleted > 0);

        return $totalDeleted;
    }
}
