<?php

namespace DagaSmart\TaskSchedule;

use Cron\CronExpression;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\TextControl;
use DagaSmart\BizAdmin\Extend\ServiceProvider;

use DagaSmart\TaskSchedule\Listeners\ScheduledTaskFailedListener;
use DagaSmart\TaskSchedule\Listeners\ScheduledTaskFinishedListener;
use DagaSmart\TaskSchedule\Listeners\ScheduledTaskStartingListener;




class TaskScheduleServiceProvider extends ServiceProvider
{
    protected $menu = [
        [
            'parent' => NULL,
            'title' => '任务调度',
            'url' => '/task-schedule',
            'url_type' => 1,
            'icon' => 'carbon:event-schedule',
        ],
        [
            'parent' => '任务调度',
            'title' => '任务列表',
            'url' => '/task-schedule/index',
            'url_type' => 1,
            'icon' => 'mdi-light:console',
        ],
        [
            'parent' => '任务调度',
            'title' => '任务分组',
            'url' => '/task-schedule/group',
            'url_type' => 1,
            'icon' => 'mdi-light:folder-multiple',
        ],
        [
            'parent' => '任务调度',
            'title' => '统计分析',
            'url' => '/task-schedule/stat',
            'url_type' => 1,
            'icon' => 'mdi-light:signal',
        ],
    ];

    public function settingForm(): Form
    {
        return $this->baseSettingForm()->body([
            TextControl::make()->name('value')->label('Value')->required(),
        ]);
    }

    public function boot(): void
    {
        parent::boot();

        $this->extendValidationRules();
        $this->setupConfig();

        if ($this->app->runningInConsole()) {
            $this->listenEvents();

            // ✅ Laravel 13 唯一可靠方式
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $this->schedule($schedule);
            });
        }
    }

    protected function listenEvents(): void
    {
        $this->app['events']->listen(ScheduledTaskStarting::class, ScheduledTaskStartingListener::class);
        $this->app['events']->listen(ScheduledTaskFinished::class, ScheduledTaskFinishedListener::class);
        $this->app['events']->listen(ScheduledTaskFailed::class, ScheduledTaskFailedListener::class);
    }

    protected function extendValidationRules(): void
    {
        Validator::extend('cron_expression', function ($attribute, $value, $parameters, $validator) {
            return CronExpression::isValidExpression($value);
        });
    }

    protected function setupConfig(): void
    {
        $configPath = dirname(__DIR__, 1).'/config/schedule.php';

        if ($this->app->runningInConsole()) {
            $this->publishes([$configPath => config_path('schedule.php')], 'schedule');
        }

        $this->mergeConfigFrom($configPath, 'schedule');
    }

    protected function setupMigration(): void
    {
        $this->publishes([
            dirname(__DIR__, 1) . '/database/migrations/create_task_schedule_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_task_schedule_table.php'),
            dirname(__DIR__, 1) . '/database/migrations/create_task_schedule_group_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_task_schedule_group_table.php'),
            dirname(__DIR__, 1) . '/database/migrations/create_task_schedule_log_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_task_schedule_log_table.php'),
        ], 'migrations');
    }

    /**
     * Prepare schedule from tasks.
     *
     * @param  Schedule  $schedule
     */
    protected function schedule(Schedule $schedule): void
    {
        try {
            $schedules = app(Config::get('schedule.model'))
                ->active()
                ->get();
        } catch (QueryException $e) {
            $schedules = collect();
        }

        if ($schedules->isEmpty()) {
            return;
        }

        $schedules->each(function ($item) use ($schedule) {

            // ✅ 命令为空跳过
            if (empty($item->command)) {
                return;
            }

            // ✅ 校验 cron 表达式合法性（核心修复）
            if (empty($item->expression) || !CronExpression::isValidExpression($item->expression)) {
                logger()->warning("TaskSchedule [ID:{$item->id}] 跳过非法 cron 表达式: {$item->expression}");
                return; // ← 跳过这条任务，不注册到 Schedule
            }

            // ✅ 清理命令名：去掉 php artisan 前缀
            $command = trim($item->command);
            $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
            $command = preg_replace('/^artisan\s+/i', '', $command);

            if (empty($command)) {
                return;
            }

            // ✅ 参数拆成数组（DB 里存 JSON 数组最稳，退路按空格拆）
            $params = trim($item->parameters ?? '');
            $paramArray = $params !== ''
                ? (json_decode($params, true) ?? str_getcsv($params, ' '))
                : [];

            // ✅ 用官方 API，命令名和参数分离
            $event = $schedule->command($command, $paramArray);

            // ✅ cron + name + timezone
            $event->cron($item->expression ?? '* * * * *')
                ->name(trim((string)$item->description) ?: $item->command)
                ->timezone($item->timezone ?? null);

            // ✅ 环境限制
            if (!empty($item->environments)) {
                $envs = is_array($item->environments)
                    ? $item->environments
                    : explode(',', $item->environments);
                $event->environments($envs);
            }

            // ✅ 防重叠释放锁时间
            if (!empty($item->without_overlapping)) {
                $minutes = 1440; // 默认 24 小时
                if (is_numeric($item->without_overlapping) && $item->without_overlapping > 0) {
                    $minutes = min((int) $item->without_overlapping, 10080); // ← 最多 7 天，防止手误填天文数字
                }
                $event->withoutOverlapping($minutes);
            }

            // ✅ 单服务器
            if (!empty($item->on_one_server)) {
                $event->onOneServer();
            }

            // ✅ 后台运行
            if (!empty($item->in_background)) {
                $event->runInBackground();
            }

            // ✅ 维护模式也跑
            if (!empty($item->in_maintenance_mode)) {
                $event->evenInMaintenanceMode();
            }

            // ✅ 输出到文件
            if (!empty($item->output_file_path)) {
                $path = Config::get('schedule.output.path') . $item->output_file_path;
                !empty($item->output_append)
                    ? $event->appendOutputTo($path)
                    : $event->sendOutputTo($path);
            }

            // ✅ 输出发邮件
            if (!empty($item->output_email)) {
                !empty($item->output_email_on_failure)
                    ? $event->emailOutputOnFailure($item->output_email)
                    : $event->emailOutputTo($item->output_email);
            }
        });
    }

}
