<?php

namespace DagaSmart\TaskSchedule\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use DagaSmart\TaskSchedule\Models\TaskScheduleGroup;
use DagaSmart\TaskSchedule\Models\TaskSchedule;

class TaskScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 创建默认分组
        $groups = $this->seedGroups();

        // 创建示例任务
        $this->seedTasks($groups);
    }

    /**
     * 种子分组
     */
    private function seedGroups(): array
    {
        $groups = [
            [
                'group_name' => '系统维护',
                'description' => '系统级维护任务',
                'sort' => 10,
                'parent_id' => null,
            ],
            [
                'group_name' => '数据同步',
                'description' => '数据同步相关任务',
                'sort' => 20,
                'parent_id' => null,
            ],
            [
                'group_name' => '业务处理',
                'description' => '业务定时处理任务',
                'sort' => 30,
                'parent_id' => null,
            ],
            [
                'group_name' => '监控告警',
                'description' => '监控和告警任务',
                'sort' => 40,
                'parent_id' => null,
            ],
        ];

        $created = [];
        foreach ($groups as $group) {
            $created[] = TaskScheduleGroup::create($group);
        }

        return $created;
    }

    /**
     * 种子任务
     */
    private function seedTasks(array $groups): void
    {
        $tasks = [
            // 秒级任务
            [
                'task_name' => '心跳检测',
                'task_type' => 'command',
                'description' => '每秒执行的心跳检测任务',
                'command' => 'schedule:heartbeat',
                'parameters' => null,
                'expression' => '*/10 * * * * *', // 每10秒
                'precision' => 1, // 秒级
                'active' => false,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 1,
                'group_id' => 4, // 监控告警
                'priority' => 100,
            ],
            // 分级任务
            [
                'task_name' => '缓存预热',
                'task_type' => 'command',
                'description' => '每5分钟预热热点缓存',
                'command' => 'cache:warm',
                'parameters' => ['--hot-only'],
                'expression' => '*/5 * * * *',
                'precision' => 2, // 分级
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 10,
                'group_id' => 1, // 系统维护
                'priority' => 50,
            ],
            [
                'task_name' => '队列监控',
                'task_type' => 'command',
                'description' => '监控队列长度并自动扩容',
                'command' => 'queue:monitor',
                'parameters' => null,
                'expression' => '*/2 * * * *',
                'precision' => 2,
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 5,
                'group_id' => 4,
                'priority' => 80,
            ],
            // 时级任务
            [
                'task_name' => '数据统计',
                'task_type' => 'command',
                'description' => '每小时统计数据',
                'command' => 'stats:aggregate',
                'parameters' => ['--period=hourly'],
                'expression' => '0 * * * *',
                'precision' => 3, // 时级
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 120,
                'group_id' => 2, // 数据同步
                'priority' => 30,
            ],
            // 天级任务
            [
                'task_name' => '日终结算',
                'task_type' => 'command',
                'description' => '每日结算处理',
                'command' => 'finance:settle',
                'parameters' => ['--date=yesterday'],
                'expression' => '0 1 * * *',
                'precision' => 4, // 天级
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 1440,
                'group_id' => 3, // 业务处理
                'priority' => 90,
                'max_runtime' => 3600,
                'retry_times' => 3,
                'retry_interval' => 300,
            ],
            [
                'task_name' => '日志清理',
                'task_type' => 'command',
                'description' => '清理过期日志',
                'command' => 'log:clean',
                'parameters' => ['--days=30'],
                'expression' => '0 3 * * *',
                'precision' => 4,
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 120,
                'group_id' => 1,
                'priority' => 10,
            ],
            // 周级任务
            [
                'task_name' => '周报生成',
                'task_type' => 'command',
                'description' => '每周一生成周报',
                'command' => 'report:weekly',
                'parameters' => null,
                'expression' => '0 8 * * 1',
                'precision' => 5, // 周级
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 1440,
                'group_id' => 3,
                'priority' => 60,
            ],
            [
                'task_name' => '数据库优化',
                'task_type' => 'command',
                'description' => '每周日优化数据库',
                'command' => 'db:optimize',
                'parameters' => ['--vacuum'],
                'expression' => '0 4 * * 0',
                'precision' => 5,
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes => 2880',
                'group_id' => 1,
                'priority' => 40,
                'max_runtime' => 7200,
            ],
            // 月级任务
            [
                'task_name' => '月终结算',
                'task_type' => 'command',
                'description' => '每月1号执行月结',
                'command' => 'finance:monthly-close',
                'parameters' => null,
                'expression' => '0 2 1 * *',
                'precision' => 6, // 月级
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 4320,
                'group_id' => 3,
                'priority' => 95,
                'max_runtime' => 14400,
                'retry_times' => 5,
                'retry_interval' => 600,
            ],
            [
                'task_name' => '数据归档',
                'task_type' => 'command',
                'description' => '每月归档历史数据',
                'command' => 'data:archive',
                'parameters' => ['--older-than=90'],
                'expression' => '0 5 1 * *',
                'precision' => 6,
                'active' => true,
                'timezone' => 'Asia/Shanghai',
                'without_overlapping' => true,
                'overlap_release_minutes' => 4320,
                'group_id' => 2,
                'priority' => 70,
                'max_runtime' => 10800,
            ],
        ];

        foreach ($tasks as $task) {
            TaskSchedule::create($task);
        }
    }
}
