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

    /**
     * 运行指定任务
     */
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

        if ($sync) {
            return $this->runSync($task);
        }

        return $this->dispatchTask($task);
    }

    /**
     * 运行到期的所有任务
     */
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
            if ($result === self::SUCCESS) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->info("Completed: {$success} succeeded, {$failed} failed");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 同步执行
     */
    private function runSync(TaskSchedule $task): int
    {
        $dispatchId = uniqid('disp_', true);

        // 写 run 记录
        $run = TaskScheduleRun::create([
            'task_id' => $task->id,
            'task_name' => $task->task_name,
            'command' => $task->command,
            'description' => $task->description,
            'dispatch_id' => $dispatchId,
            'worker_id' => gethostname() . '_' . getmypid(),
            'server_id' => gethostname(),
            'state' => TaskState::RUNNING->value,
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
                    'state' => TaskState::SKIPPED->value,
                    'finished_at' => now(),
                ]);
                $this->warn("Task [{$task->id}] skipped (overlap)");
                return self::FAILURE;
            }
        }

        $startTime = microtime(true);

        try {
            $command = trim($task->command ?? '');
            $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
            $command = preg_replace('/^artisan\s+/i', '', $command);

            $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

            if ($task->max_runtime > 0) {
                $fullCommand = sprintf('timeout %d %s', $task->max_runtime, $fullCommand);
            }

            $exitCode = 0;
            system($fullCommand, $exitCode);

            $duration = round(microtime(true) - $startTime, 4);
            $state = $exitCode === 0 ? TaskState::SUCCESS : TaskState::FAILED;

            $run->update([
                'state' => $state->value,
                'exit_code' => $exitCode,
                'duration' => $duration,
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
                'state' => TaskState::FAILED->value,
                'exit_code' => 1,
                'duration' => $duration,
                'output' => ['error' => $e->getMessage()],
                'finished_at' => now(),
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
     * 异步分发
     */
    private function dispatchTask(TaskSchedule $task): int
    {
        // 检查是否可执行
        if (!$this->canRunTask($task)) {
            $this->warn("Task [{$task->id}] cannot run now (overlap/skip)");
            return self::FAILURE;
        }

        // 更新运行时间
        $task->update([
            'last_run_at' => now(),
            'next_run_at' => $task->calculateNextRun(),
        ]);

        // 写 run 记录
        $dispatchId = uniqid('disp_', true);
        TaskScheduleRun::create([
            'task_id' => $task->id,
            'task_name' => $task->task_name,
            'command' => $task->command,
            'description' => $task->description,
            'dispatch_id' => $dispatchId,
            'worker_id' => gethostname() . '_' . getmypid(),
            'server_id' => gethostname(),
            'state' => TaskState::RUNNING->value,
            'started_at' => now(),
        ]);

        // 后台执行
        $command = trim($task->command ?? '');
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $fullCommand = sprintf('%s artisan %s > /dev/null 2>&1 &', PHP_BINARY, $command);
        exec($fullCommand);

        $this->info("Task [{$task->id}] dispatched");
        return self::SUCCESS;
    }

    /**
     * 检查任务是否可执行
     */
    private function canRunTask(TaskSchedule $task): bool
    {
        // 防重叠
        if ($task->without_overlapping) {
            $lockKey = "task_exec:{$task->id}";
            $ttl = ($task->overlap_release_minutes ?? 5) * 60;
            if (!Cache::lock($lockKey, $ttl)->get()) {
                return false;
            }
            // 立即释放（dispatchTask 只负责分发，不持有锁）
            Cache::lock($lockKey)->forceRelease();
        }

        // 检查是否在维护模式
        if (app()->isDownForMaintenance() && !$task->run_in_maintenance) {
            return false;
        }

        return true;
    }
}
