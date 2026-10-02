<?php
declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 任务调度表配置
    |--------------------------------------------------------------------------
    */
    'table' => 'task_schedule',
    'group' => 'task_schedule_group',
    'log' => 'task_schedule_log',
    'dispatch' => 'task_schedule_dispatch',

    /*
    |--------------------------------------------------------------------------
    | 模型类
    |--------------------------------------------------------------------------
    */
    'model' => \DagaSmart\TaskSchedule\Models\TaskSchedule::class,
    'group_model' => \DagaSmart\TaskSchedule\Models\TaskScheduleGroup::class,
    'log_model' => \DagaSmart\TaskSchedule\Models\TaskScheduleLog::class,
    'dispatch_model' => \DagaSmart\TaskSchedule\Models\TaskScheduleDispatch::class,

    /*
    |--------------------------------------------------------------------------
    | 输出路径
    |--------------------------------------------------------------------------
    */
    'output' => [
        'path' => storage_path('logs/tasks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 调度精度配置（支持秒级调度）
    |--------------------------------------------------------------------------
    */
    'precision' => [
        // 调度器tick间隔（秒），0.1 = 100ms 精度
        'tick_interval' => env('SCHEDULE_TICK_INTERVAL', 1),
        // 最大并发协程数
        'max_concurrency' => env('SCHEDULE_MAX_CONCURRENCY', 256),
        // 调度器worker数量
        'workers' => env('SCHEDULE_WORKERS', 4),
        // 心跳间隔（秒）
        'heartbeat_interval' => env('SCHEDULE_HEARTBEAT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Swow 事件循环配置
    |--------------------------------------------------------------------------
    */
    'swow' => [
        // 是否启用 Swow 协程调度器
        'enabled' => env('SCHEDULE_SWOW_ENABLED', true),
        // 协程最大并发数
        'max_coroutines' => env('SCHEDULE_SWOW_MAX_COROUTINES', 1024),
        // 协程栈大小（字节）
        'stack_size' => env('SCHEDULE_SWOW_STACK_SIZE', 8388608), // 8MB
        // 事件循环tick间隔（毫秒）
        'loop_tick_ms' => env('SCHEDULE_SWOW_LOOP_TICK', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL 分布式锁配置
    |--------------------------------------------------------------------------
    */
    'locking' => [
        // 锁类型: advisory (推荐) | cache
        'driver' => env('SCHEDULE_LOCK_DRIVER', 'advisory'),
        // 锁超时（秒）
        'timeout' => env('SCHEDULE_LOCK_TIMEOUT', 300),
        // 锁命名空间
        'namespace' => env('SCHEDULE_LOCK_NAMESPACE', 0x5441534B), // 'TASK' in hex
    ],

    /*
    |--------------------------------------------------------------------------
    | 日志配置
    |--------------------------------------------------------------------------
    */
    'logging' => [
        // 日志保留天数
        'retention_days' => env('SCHEDULE_LOG_RETENTION_DAYS', 30),
        // 是否记录每次执行
        'log_all_executions' => env('SCHEDULE_LOG_ALL', true),
        // 日志异步写入
        'async' => env('SCHEDULE_LOG_ASYNC', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | 监控配置
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        // Prometheus 指标前缀
        'metrics_prefix' => env('SCHEDULE_METRICS_PREFIX', 'task_schedule'),
        // 慢任务阈值（秒）
        'slow_task_threshold' => env('SCHEDULE_SLOW_THRESHOLD', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | 任务类型枚举（用于命令描述）
    |--------------------------------------------------------------------------
    */
    'enum' => \DagaSmart\TaskSchedule\Enums\TaskType::class,
];
