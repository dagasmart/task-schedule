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
            //$this->setupMigration(); //建表

            $this->listenEvents();

            $this->app->resolving(Schedule::class, function ($schedule) {
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
        $commands = app(Kernel::class)->all();

        try {
            $schedules = app(Config::get('schedule.model'))->active()->get();
        } catch (QueryException $exception) {
            $schedules = collect();
        }

        $schedules->each(function ($item) use ($schedule, $commands) {
            $event = $schedule->command($item->command.' '.$item->parameters);
            $event->cron($item->expression)
                ->name($item->description)
                ->timezone($item->timezone);

            $callbacks = ['skip', 'when', 'before', 'after', 'onSuccess', 'onFailure'];
            foreach ($callbacks as $callback) {
                if (isset($commands[$item->command]) && method_exists($commands[$item->command], $callback)) {
                    $event->$callback($commands[$item->command]->$callback($event, $item));
                }
            }

            if ($item->environments) {
                $event->environments($item->environments);
            }

            if ($item->without_overlapping) {
                $event->withoutOverlapping($item->without_overlapping);
            }

            if ($item->on_one_server) {
                $event->onOneServer();
            }

            if ($item->in_background) {
                $event->runInBackground();
            }

            if ($item->in_maintenance_mode) {
                $event->evenInMaintenanceMode();
            }

            if ($item->output_file_path) {
                if ($item->output_append) {
                    $event->appendOutputTo(Config::get('schedule.output.path').Str::start($item->output_file_path, DIRECTORY_SEPARATOR));
                } else {
                    $event->sendOutputTo(Config::get('schedule.output.path').Str::start($item->output_file_path, DIRECTORY_SEPARATOR));
                }
            }

            if ($item->output_email) {
                if ($item->output_email_on_failure) {
                    $event->emailOutputOnFailure($item->output_email);
                } else {
                    $event->emailOutputTo($item->output_email);
                }
            }
        });
    }

}
