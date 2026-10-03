<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Enums\TaskState;

class ScheduleRunCommand extends Command
{
    protected $signature = 'schedule:run
                            {--id= : Run specific task by ID}
                            {--sync : Run synchronously}
                            {--force : Force run even if not due}';

    protected $description = 'Run scheduled tasks (single execution or specific task)';

    public function handle(): int
    {
        $taskId = $this->option('id');
        $sync = $this->option('sync');
        $force = $this->option('force');

        if ($taskId) {
            return $this->runSpecificTask((int) $taskId, $sync);
        }

        return $this->runDueTasks($force);
    }

    private function runSpecificTask(int $taskId, bool $sync): int
    {
        $task = TaskSchedule::query()->find($taskId);

        if (!$task) {
            $this->error("Task [{$taskId}] not found");
            return self::FAILURE;
        }

        if (!$task->is_active && !$this->option('force')) {
            $this->error("Task [{$taskId}] is not active");
            return self::FAILURE;
        }

        $this->info("Running task [{$taskId}]: {$task->task_name}");

        return $sync ? $this->runSync($task) : $this->dispatchTask($task);
    }

    private function runDueTasks(bool $force): int
    {
        $query = TaskSchedule::query()->active();

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            });
        }

        $tasks = $query->orderBy('priority', 'desc')->get();

        if ($tasks->isEmpty()) {
            $this->info('No due tasks found');
            return self::SUCCESS;
        }

        $this->info("Found {$tasks->count()} due task(s)");

        $success = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            $result = $this->dispatchTask($task);
            $result === self::SUCCESS ? $success++ : $failed++;
        }

        $this->info("Completed: {$success} succeeded, {$failed} failed");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 同步执行 —— 只写 run 表
     */
    private function runSync(TaskSchedule $task): int
    {
        $run = TaskScheduleRun::create([
            'task_id'    => $task->id,
            'event_name' => $task->command ?? 'manual',   // 命令即事件名
            'command'    => $task->command,
            'expression' => $task->expression ?? null,
            'timezone'   => $task->timezone ?? null,
            'state'      => TaskState::RUNNING->value,
            'started_at' => now(),
        ]);

        // 防重叠锁
        $lockAcquired = false;
        if ($task->without_overlapping) {
            $lockKey = "task_exec:{$task->id}";
            $ttl = ($task->overlap_release_minutes ?? 5) * 60;
            $lockAcquired = Cache::lock($lockKey, $ttl)->get();
            if (!$lockAcquired) {
                $run->update([
                    'state'       => TaskState::SKIPPED->value,
                    'mutex_name'  => "task_exec:{$task->id}",
                    'skipped_because_overlapping' => true,
                    'finished_at' => now(),
                ]);
                $this->warn("Task [{$task->id}] skipped (overlap)");
                return self::FAILURE;
            }
        }

        $startTime = microtime(true);

        try {
            $command = preg_replace('/^php\s+artisan\s+/i', '', trim($task->command ?? ''));
            $command = preg_replace('/^artisan\s+/i', '', $command);
            $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

            if ($task->max_runtime > 0) {
                $fullCommand = sprintf('timeout %d %s', $task->max_runtime, $fullCommand);
            }

            $exitCode = 0;
            system($fullCommand, $exitCode);

            $duration = round(microtime(true) - $startTime, 4);
            $state = $exitCode === 0 ? TaskState::SUCCESS : TaskState::FAILED;

            // 只更新 run 表
            $run->update([
                'state'       => $state->value,
                'exit_code'   => $exitCode,
                'duration'    => $duration,
                'finished_at' => now(),
            ]);

            $task->update([
                'last_run_at' => now(),
                'next_run_at' => $task->calculateNextRun(),
            ]);

            $this->info("Task [{$task->id}] finished: exit={$exitCode} duration={$duration}s");
            return $exitCode === 0 ? self::SUCCESS : self::FAILURE;

        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 4);

            $run->update([
                'state'         => TaskState::FAILED->value,
                'exit_code'     => 1,
                'error_message' => $e->getMessage(),
                'duration'      => $duration,
                'finished_at'   => now(),
            ]);

            $this->error("Task [{$task->id}] failed: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            if ($lockAcquired) {
                Cache::lock("task_exec:{$task->id}")->forceRelease();
            }
        }
    }

    /**
     * 异步分发 —— 只写 run 表
     */
    private function dispatchTask(TaskSchedule $task): int
    {
        if (!$this->canRunTask($task)) {
            $this->warn("Task [{$task->id}] cannot run now (overlap/skip)");
            return self::FAILURE;
        }

        $task->update([
            'last_run_at' => now(),
            'next_run_at' => $task->calculateNextRun(),
        ]);

        // 只写 run 表，字段严格对齐迁移定义
        TaskScheduleRun::create([
            'task_id'    => $task->id,
            'event_name' => $task->command ?? 'manual',
            'command'    => $task->command,
            'expression' => $task->expression ?? null,
            'timezone'   => $task->timezone ?? null,
            'state'      => TaskState::RUNNING->value,
            'started_at' => now(),
        ]);

        $command = preg_replace('/^php\s+artisan\s+/i', '', trim($task->command ?? ''));
        $command = preg_replace('/^artisan\s+/i', '', $command);
        $fullCommand = sprintf('%s artisan %s > /dev/null 2>&1 &', PHP_BINARY, $command);
        exec($fullCommand);

        $this->info("Task [{$task->id}] dispatched");
        return self::SUCCESS;
    }

    private function canRunTask(TaskSchedule $task): bool
    {
        if ($task->without_overlapping) {
            $lockKey = "task_exec:{$task->id}";
            $ttl = ($task->overlap_release_minutes ?? 5) * 60;
            if (!Cache::lock($lockKey, $ttl)->get()) {
                return false;
            }
            Cache::lock($lockKey)->forceRelease();
        }

        if (app()->isDownForMaintenance() && !$task->run_in_maintenance) {
            return false;
        }

        return true;
    }
}
