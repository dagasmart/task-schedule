<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule;

use Cron\CronExpression;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Extend\ServiceProvider;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Listeners\ScheduledTaskFailedListener;
use DagaSmart\TaskSchedule\Listeners\ScheduledTaskFinishedListener;
use DagaSmart\TaskSchedule\Listeners\ScheduledTaskStartingListener;
use DagaSmart\TaskSchedule\Console\Commands\ScheduleSwowRunCommand;
use DagaSmart\TaskSchedule\Console\Commands\ScheduleRunCommand;
use DagaSmart\TaskSchedule\Console\Commands\ScheduleWorkCommand;
use DagaSmart\TaskSchedule\Console\Commands\ScheduleCleanupCommand;
use Illuminate\Console\Command;

class TaskScheduleServiceProvider extends ServiceProvider
{
    /**
     * 菜单配置
     */
    protected $menu = [
        [
            'parent' => null,
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
            'title' => '任务日志',
            'url' => '/task-schedule/log',
            'url_type' => 1,
            'icon' => 'mdi-light:clipboard-text',
        ],
        [
            'parent' => '任务调度',
            'title' => '统计分析',
            'url' => '/task-schedule/stat',
            'url_type' => 1,
            'icon' => 'mdi-light:signal',
        ],
    ];

    /**
     * 命令列表
     */
    protected $commands = [
        ScheduleSwowRunCommand::class,
        ScheduleRunCommand::class,
        ScheduleWorkCommand::class,
        ScheduleCleanupCommand::class,
    ];

    /**
     * @throws Exception
     */
    public function register(): void
    {
        parent::register();

        /**✅ 加载路由*/
        parent::registerRoutes(__DIR__.'/Http/routes.php');

        /**✅ 加载语言包*/
        if ($lang = parent::getLangPath()) {
            $this->loadTranslationsFrom($lang, $this->getCode());
        }

    }

    public function boot(): void
    {
        parent::boot();

        $this->extendValidationRules();
        $this->setupConfig();
        $this->registerCommands();

        if ($this->app->runningInConsole()) {
            $this->listenEvents();
            $this->registerSchedule();
        }
    }

    /**
     * 注册配置
     */
    protected function setupConfig(): void
    {
        $configPath = dirname(__DIR__, 1) . '/config/schedule.php';

        if ($this->app->runningInConsole()) {
            $this->publishes([$configPath => config_path('schedule.php')], 'schedule-config');
        }

        $this->mergeConfigFrom($configPath, 'schedule');
    }

    /**
     * 注册命令
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->discoverCommands());
        }
    }
    /**
     * 自动扫描 Console/Commands 目录下的所有命令类
     *
     * @return array<class-string<Command>>
     */
    protected function discoverCommands(): array
    {
        $commands = [];
        $dir = __DIR__.'/Console/Commands';

        if (! is_dir($dir)) {
            return $commands;
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = __NAMESPACE__.'\\Console\\Commands\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (is_subclass_of($class, Command::class)) {
                $commands[] = $class;
            }
        }

        return $commands;
    }

    /**
     * 注册调度任务
     */
    protected function registerSchedule(): void
    {
        // 使用 Laravel 13 新特性：通过闭包注册
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $this->schedule($schedule);
        });
    }

    /**
     * 定义调度任务
     */
    protected function schedule(Schedule $schedule): void
    {
        // 1. 秒/分级任务 - 使用 Swow 调度器（推荐）
        $schedule->command('schedule:swow-run --workers=4 --max-concurrency=1024 --tick-ms=10')
            ->everyMinute()
            ->withoutOverlapping(120)
            ->onOneServer() // 依赖 Redis cache driver + 多服务器部署才有意义
            ->runInBackground();

//        // 2. 高精度秒级任务（不使用 Swow 时的备选方案）
//        $schedule->command('schedule:work --interval=1 --precision=1')
//            ->everyMinute()
//            ->withoutOverlapping(120)
//            ->runInBackground();
//
//        // 3. 分级任务（不使用 Swow 时的备选方案）
//        $schedule->command('schedule:work --interval=60 --precision=2')
//            ->everyMinute()
//            ->withoutOverlapping(120)
//            ->runInBackground();

        // 4. 清理过期日志（每天凌晨执行）
        $schedule->command('schedule:cleanup --days=30 --optimize')
            ->dailyAt('03:00')
            ->withoutOverlapping();

        // 5. 健康检查
        $schedule->call(function () {
            $this->healthCheck();
        })
            ->everyFiveMinutes()
            ->name('task-schedule-health-check');
    }

    /**
     * 健康检查
     */
    protected function healthCheck(): void
    {
        try {
            // 检查数据库连接
            DB::connection()->getPdo();

            // 检查 Swow 调度器心跳
            $heartbeats = Cache::get('scheduler:heartbeats', []);
            $staleWorkers = [];

            foreach ($heartbeats as $workerId => $data) {
                if (now()->diffInMinutes($data['last_seen']) > 5) {
                    $staleWorkers[] = $workerId;
                }
            }

            if (!empty($staleWorkers)) {
                Log::warning('Stale scheduler workers detected: ' . implode(', ', $staleWorkers));
            }
        } catch (\Throwable $e) {
            Log::error('Scheduler health check failed: ' . $e->getMessage());
        }
    }

    /**
     * 注册事件监听
     */
    protected function listenEvents(): void
    {
        Event::listen(ScheduledTaskStarting::class, ScheduledTaskStartingListener::class);
        Event::listen(ScheduledTaskFinished::class, ScheduledTaskFinishedListener::class);
        Event::listen(ScheduledTaskFailed::class, ScheduledTaskFailedListener::class);
    }

    /**
     * 扩展验证规则
     */
    protected function extendValidationRules(): void
    {
        Validator::extend('cron_expression', function ($attribute, $value, $parameters, $validator) {
            // 支持 5 字段（分 时 日 月 周）和 6 字段（秒 分 时 日 月 周）
            $parts = explode(' ', trim($value));

            if (count($parts) === 6) {
                // 6 字段格式：验证秒字段
                $seconds = $parts[0];
                if (!$this->isValidSecondExpression($seconds)) {
                    return false;
                }
                // 验证后 5 字段
                $cronPart = implode(' ', array_slice($parts, 1));
                return CronExpression::isValidExpression($cronPart);
            }

            return CronExpression::isValidExpression($value);
        });

        Validator::extend('task_name_unique', function ($attribute, $value, $parameters, $validator) {
            $query = TaskSchedule::query()->where('task_name', $value);

            if (!empty($parameters[0])) {
                $query->where('id', '!=', $parameters[0]);
            }

            return !$query->exists();
        });
    }

    /**
     * 验证秒字段表达式
     */
    private function isValidSecondExpression(string $expression): bool
    {
        // 允许：数字、逗号、连字符、星号、斜杠、逗号分隔的范围
        if (!preg_match('/^[\d,\-\*\/]+$/', $expression)) {
            return false;
        }

        // 检查每个数值是否在 0-59 范围内
        $parts = explode(',', $expression);
        foreach ($parts as $part) {
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part);
                if (!is_numeric($step) || (int)$step < 1 || (int)$step > 59) {
                    return false;
                }
                $part = $range;
            }

            if ($part === '*') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$start, $end] = explode('-', $part);
                if (!is_numeric($start) || !is_numeric($end)) {
                    return false;
                }
                if ((int)$start < 0 || (int)$start > 59 || (int)$end < 0 || (int)$end > 59) {
                    return false;
                }
                continue;
            }

            if (is_numeric($part)) {
                $val = (int)$part;
                if ($val < 0 || $val > 59) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 设置迁移发布
     */
    protected function setupMigration(): void
    {
        $this->publishes([
            dirname(__DIR__, 1) . '/Database/Migrations/' => database_path('migrations'),
        ], 'schedule-migrations');
    }

    public function settingForm(): ?Form
    {
        return $this->baseSettingForm()->body([
            amis()->SwitchControl('swow.enabled', '调度引擎')
                ->onText('协程')
                ->offText('原生')
                ->value(0)
                ->required(),
            amis()->NumberControl('swow.max_coroutines', '协程最大并发数')
                ->size('sm')
                ->hiddenOn('${!swow.enabled}')
                ->clearValueOnHidden(true)  // ✅ 隐藏时清空值，不提交
                ->value(1024),
            amis()->NumberControl('swow.stack_size', '协程栈大小（字节）')
                ->size('sm')
                ->hiddenOn('${!swow.enabled}')
                ->clearValueOnHidden(true)  // ✅ 隐藏时清空值，不提交
                ->value(8388608),
            amis()->NumberControl('swow.loop_tick_ms', '事件循环tick间隔（毫秒）')
                ->size('sm')
                ->hiddenOn('${!swow.enabled}')
                ->clearValueOnHidden(true)  // ✅ 隐藏时清空值，不提交
                ->value(10),
        ]);
    }

}
