<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Listeners;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Log;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

class ScheduledTaskStartingListener
{
    public function handle(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $command = $task->command ?? 'unknown';
        $expression = $task->expression ?? null;
        $timezone = $task->timezone ?? null;
        $eventName = $task->name() ?? $command;
        $taskId = $this->resolveTaskId($command);

        Log::info('Scheduled task starting', [
            'command' => $command,
            'expression' => $expression,
        ]);

        // ========== task_schedule_run ==========
        try {
            TaskScheduleRun::create([
                'task_id'          => $taskId,
                'event_name'       => $eventName,
                'command'          => $command,
                'expression'       => $expression,
                'timezone'         => $timezone,
                'state'           => 'running',
                'started_at'       => now(),
                'mutex_name'       => $task->mutexName ?? null,
                'skipped_because_overlapping' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create TaskScheduleRun: ' . $e->getMessage(), [
                'command' => $command,
            ]);
        }

        // ========== task_schedule_log ==========
        try {
            TaskScheduleLog::create([
                'task_id'     => $taskId,
                'task_name'   => $eventName,
                'command'     => $command,
                'description' => $task->description ?? null,
                'state'       => false,
                'output'      => [],
                'result'      => [],
                'duration'    => 0.0,
                'started_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create TaskScheduleLog: ' . $e->getMessage(), [
                'command' => $command,
            ]);
        }
    }

    private function resolveTaskId(string $command): ?int
    {
        return TaskSchedule::where('command', $command)
            ->orderBy('id', 'desc')
            ->value('id');
    }
}
