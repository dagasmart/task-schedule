<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

class ScheduleCleanupCommand extends Command
{
    protected $signature = 'schedule:cleanup
                            {--days=30 : Days to retain}
                            {--batch=1000 : Batch size}
                            {--optimize : Optimize tables after cleanup}
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

        $runCount = $this->cleanupTable(TaskScheduleRun::class, $days, $batch);
        $this->info("Cleaned {$runCount} run records");

        $logCount = $this->cleanupTable(TaskScheduleLog::class, $days, $batch);
        $this->info("Cleaned {$logCount} log records");

        if ($this->option('optimize')) {
            $this->optimizeTables();
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

    private function optimizeTables(): void
    {
        $driver = DB::getDriverName();
        $tables = ['task_schedule_run', 'task_schedule_log'];

        foreach ($tables as $table) {
            try {
                if ($driver === 'mysql') {
                    DB::statement("OPTIMIZE TABLE `{$table}`");
                } elseif ($driver === 'pgsql') {
                    DB::statement("VACUUM ANALYZE {$table}");
                } elseif ($driver === 'sqlite') {
                    DB::statement("VACUUM");
                }
                $this->line("  Optimized table: {$table}");
            } catch (\Throwable $e) {
                $this->warn("  Skip optimize {$table}: " . $e->getMessage());
            }
        }
    }
}
