<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Log;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

class ScheduledTaskFailedListener
{
    public function handle(ScheduledTaskFailed $event): void
    {
        $task = $event->task;
        $exception = $event->exception ?? null;
        $command = $task->command ?? 'unknown';

        Log::error('Scheduled task failed', [
            'command' => $command,
            'error'   => $exception?->getMessage(),
        ]);

        // ========== task_schedule_run ==========
        try {
            $run = TaskScheduleRun::where('command', $command)
                ->where('status', 'running')
                ->orderBy('id', 'desc')
                ->first();

            if ($run) {
                $run->update([
                    'status'        => 'failed',
                    'error_message' => $exception?->getMessage(),
                    'finished_at'   => now(),
                    'exit_code'     => $exception?->getCode() ?: 1,
                ]);
            } else {
                // 没有 running 记录，直接插入一条 failed
                TaskScheduleRun::create([
                    'task_id'       => $this->resolveTaskId($command),
                    'event_name'    => $task->name() ?? $command,
                    'command'       => $command,
                    'expression'    => $task->expression ?? null,
                    'timezone'      => $task->timezone ?? null,
                    'status'        => 'failed',
                    'error_message' => $exception?->getMessage(),
                    'finished_at'   => now(),
                    'exit_code'     => $exception?->getCode() ?: 1,
                    'started_at'    => now(),
                    'mutex_name'    => $task->mutexName ?? null,
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
                ->orderBy('id', 'desc')
                ->first();

            if ($log) {
                $log->update([
                    'state'         => false,
                    'error_message' => $exception?->getMessage(),
                    'finished_at'   => now(),
                ]);
            } else {
                // 没有进行中的记录，插入一条失败的
                TaskScheduleLog::create([
                    'task_id'       => $this->resolveTaskId($command),
                    'task_name'     => $task->name() ?? $command,
                    'command'       => $command,
                    'description'   => $task->description ?? null,
                    'state'         => false,
                    'error_message' => $exception?->getMessage(),
                    'output'        => [],
                    'result'        => [],
                    'duration'      => 0.0,
                    'started_at'   => now(),
                    'finished_at'   => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to update TaskScheduleLog: ' . $e->getMessage(), [
                'command' => $command,
            ]);
        }

        $this->sendAlert($task, $exception);
    }

    private function resolveTaskId(string $command): ?int
    {
        return TaskSchedule::where('command', $command)
            ->orderBy('id', 'desc')
            ->value('id');
    }

    private function sendAlert($task, ?\Throwable $exception): void
    {
        $message = "任务执行失败: {$task->command}\n";
        $message .= '错误: ' . ($exception?->getMessage() ?? 'Unknown');
        Log::channel('stack')->alert($message);
    }
}
