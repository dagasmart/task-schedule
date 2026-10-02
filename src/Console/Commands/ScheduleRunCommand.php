<?php

namespace DagaSmart\TaskSchedule\Console\Commands;

use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use DagaSmart\TaskSchedule\Models\TaskSchedule;

/**
 * 传统调度器命令（兼容 Laravel schedule:run）
 *
 * 支持：
 * - 月/周/天/时/分/秒 六级精度
 * - 数据库驱动的动态任务调度
 * - PostgreSQL Advisory Lock 防重叠
 */
class ScheduleRunCommand extends Command
{
    protected $signature = 'schedule:run
                            {--no-overlap : 强制禁用防重叠检查}
                            {--task= : 仅执行指定ID的任务}
                            {--pretend : 预演模式，不实际执行}';

    protected $description = '执行数据库中配置的任务调度（支持秒/分/时/天/周/月级精度）';

    private array $activeCoroutines = [];
    private int $maxParallel = 10;

    public function handle(): int
    {
        $this->info('[' . now()->format('Y-m-d H:i:s') . '] Task Scheduler starting...');

        // 获取待执行任务
        $tasks = $this->getDueTasks();

        if ($tasks->isEmpty()) {
            $this->info('No due tasks found.');
            return self::SUCCESS;
        }

        $this->info("Found {$tasks->count()} due task(s)");

        // 执行任务
        $this->executeTasks($tasks);

        $this->info('[' . now()->format('Y-m-d H:i:s') . '] Task Scheduler completed.');
        return self::SUCCESS;
    }

    /**
     * 获取待执行的任务
     */
    private function getDueTasks()
    {
        $query = TaskSchedule::query()->active()->byPriority();

        // 指定任务
        if ($taskId = $this->option('task')) {
            return $query->where('id', $taskId)->get();
        }

        // 按精度和时间筛选
        return $query->where(function ($q) {
            $q->whereNull('next_run_at')
                ->orWhere('next_run_at', '<=', now());
        })->get();
    }

    /**
     * 执行任务列表
     */
    private function executeTasks($tasks): void
    {
        $pretend = $this->option('pretend');

        foreach ($tasks as $task) {
            // 防重叠检查
            if ($task->without_overlapping && !$this->option('no-overlap')) {
                if (!$this->canRunTask($task)) {
                    $this->warn("Skipping task [ID:{$task->id}] - already running");
                    continue;
                }
            }

            if ($pretend) {
                $this->line("[PRETEND] Would execute: {$task->command}");
                continue;
            }

            // 后台执行
            if ($task->in_background) {
                $this->runInBackground($task);
            } else {
                $this->runForeground($task);
            }

            // 更新下次执行时间
            $this->updateNextRunTime($task);
        }
    }

    /**
     * 检查任务是否可以执行（防重叠）
     */
    private function canRunTask(TaskSchedule $task): bool
    {
        try {
            // PostgreSQL Advisory Lock
            $lockNamespace = Config::get('schedule.locking.namespace', 0x5441534B);
            $lockKey = ($lockNamespace << 32) | ($task->id & 0xFFFFFFFF);

            $result = DB::selectOne(
                'SELECT pg_try_advisory_lock(?) as acquired',
                [$lockKey]
            );

            return (bool) ($result->acquired ?? true);
        } catch (\Throwable $e) {
            // 非 PG 环境使用文件锁
            $lockFile = storage_path("app/locks/task_{$task->id}.lock");
            return !file_exists($lockFile) || time() - filemtime($lockFile) > ($task->overlap_release_minutes * 60);
        }
    }

    /**
     * 前台执行任务
     */
    private function runForeground(TaskSchedule $task): void
    {
        $this->line("Executing [ID:{$task->id}] {$task->command}");

        $start = microtime(true);

        try {
            $exitCode = $this->dispatchTask($task);

            $duration = round(microtime(true) - $start, 4);

            if ($exitCode === 0) {
                $this->info("✓ [ID:{$task->id}] Completed in {$duration}s");
            } else {
                $this->error("✗ [ID:{$task->id}] Failed with exit code {$exitCode}");
            }

            $this->logExecution($task, $exitCode, $duration);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $start, 4);
            $this->error("✗ [ID:{$task->id}] Exception: {$e->getMessage()}");
            $this->logExecution($task, 1, $duration, $e->getMessage());
        }
    }

    /**
     * 后台执行任务
     */
    private function runInBackground(TaskSchedule $task): void
    {
        $command = $this->buildCommand($task);
        $outputRedirect = '';

        if (!empty($task->output_file_path)) {
            $path = Config::get('schedule.output.path') . '/' . $task->output_file_path;
            $outputRedirect = $task->output_append ? " >> {$path} 2>&1" : " > {$path} 2>&1";
        }

        $fullCommand = sprintf(
            'nohup %s %s &',
            $command,
            $outputRedirect
        );

        exec($fullCommand);
        $this->info("→ [ID:{$task->id}] Started in background");
    }

    /**
     * 构建执行命令
     */
    private function buildCommand(TaskSchedule $task): string
    {
        $command = trim($task->command);
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, escapeshellarg($command));

        if (!empty($task->parameters)) {
            $params = is_array($task->parameters)
                ? $task->parameters
                : explode(' ', $task->parameters);

            foreach ($params as $param) {
                $fullCommand .= ' ' . escapeshellarg($param);
            }
        }

        return $fullCommand;
    }

    /**
     * 派发任务执行
     */
    private function dispatchTask(TaskSchedule $task): int
    {
        $command = trim($task->command);

        // 清理命令
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        // 解析参数
        $params = [];
        if (!empty($task->parameters)) {
            $params = is_array($task->parameters)
                ? $task->parameters
                : preg_split('/\s+/', trim($task->parameters));
        }

        // 调用 Artisan
        $exitCode = Artisan::call($command, $params);

        // 输出处理
        if (!empty($task->output_email)) {
            $this->handleOutputEmail($task, Artisan::output());
        }

        return $exitCode;
    }

    /**
     * 处理输出邮件
     */
    private function handleOutputEmail(TaskSchedule $task, string $output): void
    {
        if ($task->output_email_on_failure && Artisan::lastExitCode() === 0) {
            return;
        }

        // 发送邮件逻辑
        Log::info("Would send email to {$task->output_email} with output");
    }

    /**
     * 更新下次执行时间
     */
    private function updateNextRunTime(TaskSchedule $task): void
    {
        $nextRun = $task->calculateNextRun();
        $task->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRun,
        ]);
    }

    /**
     * 记录执行日志
     */
    private function logExecution(TaskSchedule $task, int $exitCode, float $duration, ?string $error = null): void
    {
        $status = $exitCode === 0 ? 2 : 3;

        DB::table('task_schedule_log')->insert([
            'task_id' => $task->id,
            'task_name' => $task->task_name,
            'command' => $task->command,
            'description' => $task->description,
            'status' => $status,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'output' => $error ? json_encode(['error' => $error]) : null,
            'started_at' => now()->subSeconds((int) $duration),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
