<?php
declare(strict_types=1);

namespace DagaSmart\TaskSchedule\Services;

use DagaSmart\BizAdmin\Services\AdminService;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Enums\TaskState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 任务调度统计服务。
 *
 * 统计数据来源：
 *   - task_schedule_runs：调度器原生执行记录（真实、准确）；
 *   - task_schedule：任务定义（分组、状态等）。
 *
 * 统计口径：
 *   - 成功率 = success / (success + failed)，排除 running/skipped；
 *   - 健康度 = 近期实际运行次数 / 预期运行次数（需结合 cron 表达式计算）；
 *   - 平均耗时仅统计已结束的成功任务（duration 不为空）；
 *   - 分组分布：仅统计已完结记录（finished_at 不为空），避免 running 干扰结果占比。
 */
class TaskScheduleStatService extends AdminService
{
    /** 健康度统计默认观察窗口（天）。 */
    private const int DEFAULT_WINDOW_DAYS = 7;

    /** 失败 Top 榜默认条数。 */
    private const int DEFAULT_TOP_FAILURES_LIMIT = 10;

    /**
     * 概览统计数据。
     *
     * @return [
     *     task_total: int,
     *     task_active: int,
     *     run_total: int,
     *     success: int,
     *     failed: int,
     *     running: int,
     *     skipped: int,
     *     success_rate: float|null,
     *     avg_duration: float,
     *     top_failures: array<int, array{task_id: int, task_name: string, fail_cnt: int}>,
     *     window_days: int,
     * ]
     */
    public function summary(): array
    {
        $window = now()->subDays(self::DEFAULT_WINDOW_DAYS);

        // 任务定义维度
        $taskTotal  = TaskSchedule::query()->count();
        $taskActive = TaskSchedule::query()->where('active', 1)->count();

        // 执行记录维度：一次查询聚合状态计数与平均耗时
        $runAgg = TaskScheduleRun::query()
            ->where('started_at', '>=', $window)
            ->selectRaw(
                'SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as success_cnt,
                 SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as failed_cnt,
                 SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as running_cnt,
                 SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as skipped_cnt,
                 AVG(CASE WHEN state = ? AND duration IS NOT NULL THEN duration END) as avg_duration',
                [TaskState::SUCCESS->value, TaskState::FAILED->value, TaskState::RUNNING->value, TaskState::SKIPPED->value, TaskState::SUCCESS->value]
            )
            ->first();

        $success = (int) $runAgg->success_cnt;
        $failed  = (int) $runAgg->failed_cnt;
        $running = (int) $runAgg->running_cnt;
        $skipped = (int) $runAgg->skipped_cnt;
        $total   = $success + $failed + $running + $skipped;

        // 失败 Top：按任务聚合，批量预载任务名称避免 N+1
        $topFailRows = TaskScheduleRun::query()
            ->where('started_at', '>=', $window)
            ->where('state', TaskState::FAILED->value)
            ->select('task_id', DB::raw('COUNT(*) as fail_cnt'))
            ->groupBy('task_id')
            ->orderByDesc('fail_cnt')
            ->limit(self::DEFAULT_TOP_FAILURES_LIMIT)
            ->get();

        $taskIds = $topFailRows->pluck('task_id')->unique()->values()->all();
        $taskMap = TaskSchedule::query()
            ->whereIn('id', $taskIds)
            ->pluck('task_name', 'id')
            ->toArray();

        $topFailures = $topFailRows->map(function ($row) use ($taskMap) {
            return [
                'task_id'   => $row->task_id,
                'task_name' => $taskMap[$row->task_id] ?? '已删除',
                'fail_cnt'  => $row->fail_cnt,
            ];
        })->toArray();

        // 成功率：仅以已完结（success + failed）为分母，无完结记录时返回 null
        $decided      = $success + $failed;
        $successRate  = $decided > 0 ? round($success / $decided * 100, 2) : null;

        return [
            'task_total'   => $taskTotal,
            'task_active'  => $taskActive,
            'run_total'    => $total,
            'success'      => $success,
            'failed'       => $failed,
            'running'      => $running,
            'skipped'      => $skipped,
            'success_rate' => $successRate,
            'avg_duration' => round((float) ($runAgg->avg_duration ?? 0), 3),
            'top_failures' => $topFailures,
            'window_days'  => self::DEFAULT_WINDOW_DAYS,
        ];
    }

    /**
     * 每日执行量趋势（用于柱状图 / 折线图）。
     *
     * 保证返回的日期序列连续，某天无数据时补 0，避免图表断轴。
     *
     * @param int $days 统计天数，默认 14
     * @return array{
     *     dates: array<int, string>,
     *     success: array<int, int>,
     *     failed: array<int, int>,
     * }
     */
    public function dailyTrend(int $days = 7): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = TaskScheduleRun::query()
            ->where('started_at', '>=', $from)
            ->selectRaw('DATE(started_at) as date_key, state, COUNT(*) as cnt')
            ->groupBy('date_key', 'state')
            ->orderBy('date_key')
            ->get();

        // 统一将日期 key 转成 Y-m-d 字符串，避免不同数据库驱动返回类型差异
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->date_key][$row->state] = $row->cnt;
        }

        $dates         = [];
        $successSeries = [];
        $failedSeries  = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $dates[]         = $date;
            $successSeries[] = (int) ($map[$date][TaskState::SUCCESS->value] ?? 0);
            $failedSeries[]  = (int) ($map[$date][TaskState::FAILED->value] ?? 0);
        }

        return [
            'dates'   => $dates,
            'success' => $successSeries,
            'failed'  => $failedSeries,
        ];
    }

    /**
     * 每日执行量趋势（用于柱状图 / 折线图）。
     *
     * 保证返回的日期序列连续，某天无数据时补 0，避免图表断轴。
     *
     * @param int $days 统计天数，默认 14
     * @return array{
     *     dates: array<int, string>,
     *     success: array<int, int>,
     *     failed: array<int, int>,
     * }
     */
    public function monthTrend(int $months = 12): array
    {
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = TaskScheduleRun::query()
            ->where('started_at', '>=', $from)
            ->selectRaw("DATE_TRUNC('month', started_at) as month_key, state, COUNT(*) as cnt")
            ->groupBy('month_key', 'state')
            ->orderBy('month_key')
            ->get();

        // 统一 key 为 Y-m
        $map = [];
        foreach ($rows as $row) {
            // pgsql DATE_TRUNC 返回 timestamp，转字符串
            $key = $row->month_key instanceof \DateTime
                ? $row->month_key->format('Y-m')
                : substr((string) $row->month_key, 0, 7);

            $map[$key][$row->state] = $row->cnt;
        }

        $monthsList    = [];
        $successSeries = [];
        $failedSeries  = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $from->copy()->addMonths($i)->format('Y-m');
            $monthsList[]    = $month;
            $successSeries[] = (int) ($map[$month][TaskState::SUCCESS->value] ?? 0);
            $failedSeries[]  = (int) ($map[$month][TaskState::FAILED->value] ?? 0);
        }

        return [
            'dates'   => $monthsList,   // 前端仍用 dates 字段，值为 2025-01 格式
            'success' => $successSeries,
            'failed'  => $failedSeries,
        ];
    }

    /**
     * 执行结果分组分布（用于饼图）。
     *
     * 口径：仅统计已完结记录（finished_at 不为空），
     * 因为饼图表达的是"执行结果占比"，running 状态不属于最终结果。
     *
     * @return array<int, array{name: string, value: int}>
     */
    public function groupDistribution(): array
    {
        return TaskScheduleRun::query()
            ->whereNotNull('finished_at')
            ->where('started_at', '>=', now()->subDays(self::DEFAULT_WINDOW_DAYS))
            ->select('state', DB::raw('COUNT(*) as value'))
            ->groupBy('state')
            ->get()
            ->map(fn ($row) => [
                'name'  => match ($row->state) {
                    TaskState::SUCCESS->value => '成功',
                    TaskState::FAILED->value  => '失败',
                    TaskState::RETRYING->value => '运行中',
                    TaskState::RUNNING->value => '已跳过',
                    default   => (string) $row->state,
                },
                'value' => (int) $row->value,
            ])
            ->values()
            ->toArray();
    }
}
