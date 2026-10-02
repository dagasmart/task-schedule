<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;

/**
 * 调度初始化器
 *
 * 将数据库中的任务注册到 Laravel 13 的 Schedule 系统
 * 支持全新的 task attributes 特性
 */
class ScheduleInitializer
{
    private Schedule $schedule;

    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
    }

    /**
     * 从数据库初始化调度任务
     */
    public function initialize(): void
    {
        $tasks = TaskSchedule::query()
            ->active()
            ->whereIn('precision', [
                PrecisionLevel::MINUTE->value,
                PrecisionLevel::HOUR->value,
                PrecisionLevel::DAY->value,
                PrecisionLevel::WEEK->value,
                PrecisionLevel::MONTH->value,
            ])
            ->byPriority()
            ->get();

        foreach ($tasks as $task) {
            $this->registerTask($task);
        }
    }

    /**
     * 注册单个任务到调度器
     */
    private function registerTask(TaskSchedule $task): void
    {
        // 基础事件
        $event = $this->schedule->command(
            $this->buildCommand($task),
            $this->parseParameters($task->parameters)
        );

        // Cron 表达式
        $event->cron($task->expression ?? '* * * * *');

        // 任务属性（Laravel 13 新特性）
        $event->withAttributes([
            'task_id' => $task->id,
            'task_name' => $task->task_name,
            'group_id' => $task->group_id,
            'precision' => $task->precision,
        ]);

        // 时区
        if (!empty($task->timezone)) {
            $event->timezone($task->timezone);
        }

        // 环境限制
        if (!empty($task->environments)) {
            $envs = is_array($task->environments)
                ? $task->environments
                : explode(',', $task->environments);
            $event->environments($envs);
        }

        // 防重叠
        if ($task->without_overlapping) {
            $minutes = min($task->overlap_release_minutes ?: 1440, 10080);
            $event->withoutOverlapping($minutes);
        }

        // 单服务器
        if ($task->on_one_server) {
            $event->onOneServer();
        }

        // 后台运行
        if ($task->in_background) {
            $event->runInBackground();
        }

        // 维护模式
        if ($task->in_maintenance_mode) {
            $event->evenInMaintenanceMode();
        }

        // 输出
        if (!empty($task->output_file_path)) {
            $path = Config::get('schedule.output.path') . '/' . $task->output_file_path;
            if ($task->output_append) {
                $event->appendOutputTo($path);
            } else {
                $event->sendOutputTo($path);
            }
        }

        // 邮件通知
        if (!empty($task->output_email)) {
            if ($task->output_email_on_failure) {
                $event->emailOutputOnFailure($task->output_email);
            } else {
                $event->emailOutputTo($task->output_email);
            }
        }

        // 名称（用于 onOneServer 识别）
        $event->name($task->task_name ?? $task->command);
    }

    /**
     * 构建执行命令
     */
    private function buildCommand(TaskSchedule $task): string
    {
        $command = trim($task->command);

        // 清理前缀
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        return $command;
    }

    /**
     * 解析参数
     */
    private function parseParameters($parameters): array
    {
        if (empty($parameters)) {
            return [];
        }

        if (is_array($parameters)) {
            return $parameters;
        }

        if (is_string($parameters)) {
            $decoded = json_decode($parameters, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (array) $decoded;
            }
            return preg_split('/\s+/', trim($parameters));
        }

        return (array) $parameters;
    }
}
