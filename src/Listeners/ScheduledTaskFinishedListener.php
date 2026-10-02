<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Listeners;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Log;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

class ScheduledTaskFinishedListener
{
    public function handle(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $runtime = $event->runtime ?? 0;
        $command = $task->command ?? 'unknown';

        Log::info('Scheduled task finished', [
            'command' => $command,
            'runtime' => $runtime,
        ]);

        // ========== task_schedule_run ==========
        try {
            $run = TaskScheduleRun::where('command', $command)
                ->where('state', 'running')
                ->orderBy('id', 'desc')
                ->first();

            if ($run) {
                $run->update([
                    'state'      => 'success',
                    'duration'    => $runtime,
                    'finished_at' => now(),
                    'exit_code'   => 0,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to update TaskScheduleRun: ' . $e->getMessage(), [
                'command' => $command,
            ]);
        }

        // ========== task_schedule_log ==========
        try {
            $log = TaskScheduleLog::where('command', $command)
                ->where('state', false)
                ->whereNull('finished_at')
                ->orderBy('id', 'desc')
                ->first();

            if ($log) {
                $log->update([
                    'state'       => true,
                    'duration'    => $runtime,
                    'finished_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to update TaskScheduleLog: ' . $e->getMessage(), [
                'command' => $command,
            ]);
        }

        // 慢任务告警
        $slowThreshold = config('schedule.monitoring.slow_task_threshold', 30);
        if ($runtime > $slowThreshold) {
            Log::warning("Slow task detected: {$command} ({$runtime}s)");
        }
    }
}
