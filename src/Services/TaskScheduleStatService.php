<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Services;

use DagaSmart\BizAdmin\Services\AdminService;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use Illuminate\Database\Eloquent\Builder;
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
 *   - 健康度 = 近期实际运行次数 / 预期运行次数（需结合 cron 表达式计算）。
 */
class TaskScheduleStatService extends AdminService
{
    /** 健康度统计默认观察窗口（天）。 */
    private const int DEFAULT_WINDOW_DAYS = 7;

    public function summary(): array
    {
        $window = now()->subDays(self::DEFAULT_WINDOW_DAYS);

        // 任务定义维度
        $taskTotal = TaskSchedule::query()->count();
        $taskActive = TaskSchedule::query()->where('active', 1)->count();

        // 执行记录维度
        $runs = TaskScheduleRun::query()
            ->where('started_at', '>=', $window)
            ->selectRaw('state, COUNT(*) as cnt')
            ->groupBy('state')
            ->pluck('cnt', 'state')
            ->toArray();

        $success = (int) ($runs['success'] ?? 0);
        $failed  = (int) ($runs['failed'] ?? 0);
        $running = (int) ($runs['running'] ?? 0);
        $skipped = (int) ($runs['skipped'] ?? 0);
        $total   = $success + $failed + $running + $skipped;

        // 平均耗时：仅统计已结束的成功任务
        $avgDuration = TaskScheduleRun::query()
            ->where('started_at', '>=', $window)
            ->where('state', 'success')
            ->whereNotNull('duration')
            ->avg('duration') ?? 0;

        // 失败 Top：按任务聚合
        $topFailures = TaskScheduleRun::query()
            ->where('started_at', '>=', $window)
            ->where('state', 'failed')
            ->select('task_id', DB::raw('COUNT(*) as fail_cnt'))
            ->groupBy('task_id')
            ->orderByDesc('fail_cnt')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $task = TaskSchedule::query()->find($row->task_id);

                return [
                    'task_id'   => $row->task_id,
                    'task_name' => $task?->task_name ?? '已删除',
                    'fail_cnt'  => $row->fail_cnt,
                ];
            })
            ->toArray();

        return [
            'task_total'   => $taskTotal,
            'task_active'  => $taskActive,
            'run_total'    => $total,
            'success'      => $success,
            'failed'       => $failed,
            'running'      => $running,
            'skipped'      => $skipped,
            'success_rate' => $total > 0 ? round($success / ($success + $failed) * 100, 2) : 0,
            'avg_duration' => round((float) $avgDuration, 3),
            'top_failures' => $topFailures,
            'window_days'  => self::DEFAULT_WINDOW_DAYS,
        ];
    }

    /**
     * 每日执行量（用于折线图）。
     */
    public function dailyTrend(int $days = 14): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = TaskScheduleRun::query()
            ->where('started_at', '>=', $from)
            ->selectRaw('DATE(started_at) as date, state, COUNT(*) as cnt')
            ->groupBy('date', 'state')
            ->orderBy('date')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->date][$row->state] = $row->cnt;
        }

        $dates = [];
        $successSeries = [];
        $failedSeries = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $dates[] = $date;
            $successSeries[] = (int) ($map[$date]['success'] ?? 0);
            $failedSeries[]  = (int) ($map[$date]['failed'] ?? 0);
        }

        return [
            'dates'   => $dates,
            'success' => $successSeries,
            'failed'  => $failedSeries,
        ];
    }

    /**
     * 分组执行分布（用于饼图）。
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
                    'success' => '成功',
                    'failed'  => '失败',
                    'running' => '运行中',
                    'skipped' => '已跳过',
                    default   => $row->state,
                },
                'value' => $row->value,
            ])
            ->values()
            ->toArray();
    }
}
