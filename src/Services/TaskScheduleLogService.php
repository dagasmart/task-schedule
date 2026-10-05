<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Services;

use Illuminate\Database\Eloquent\Builder;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;
use DagaSmart\TaskSchedule\Enums\TaskState;

/**
 * 任务日志服务类
 */
class TaskScheduleLogService extends AdminService
{
    protected string $modelName = TaskScheduleLog::class;

    /**
     * 列表查询前处理
     */
    public function listQueryEach(Builder $query): void
    {
        // 默认按创建时间倒序
        if (!request()->orderBy) {
            $query->orderBy('created_at', 'desc');
        }
    }

    /**
     * 按状态统计
     */
    public function getStatsByState(): array
    {
        return $this->getModel()->query()
            ->select('state', DB::raw('count(*) as count'))
            ->groupBy('state')
            ->get()
            ->mapWithKeys(function ($item) {
                $state = TaskState::tryFrom($item->state);
                return [$state ? $state->label() : 'unknown' => $item->count];
            })
            ->toArray();
    }

    /**
     * 获取执行趋势（按小时）
     */
    public function getHourlyTrend(int $hours = 24): array
    {
        $table = config('schedule.log', 'task_schedule_log');
        $since = now()->subHours($hours);

        $result = $this->getModel()->query()
            ->select(
                DB::raw('EXTRACT(HOUR FROM created_at) as hour'),
                DB::raw('count(*) as total'),
                DB::raw('sum(case when state = 2 then 1 else 0 end) as success'),
                DB::raw('sum(case when state = 3 then 1 else 0 end) as failed')
            )
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('EXTRACT(HOUR FROM created_at)'))
            ->orderBy('hour')
            ->get()
            ->toArray();

        return $result;
    }

    /**
     * 获取耗时分布
     */
    public function getDurationDistribution(): array
    {
        $table = config('schedule.log', 'task_schedule_log');

        return [
            'fast' => $this->getModel()->query()->where('duration', '<', 1)->count(),      // < 1s
            'medium' => $this->getModel()->query()->whereBetween('duration', [1, 5])->count(), // 1-5s
            'slow' => $this->getModel()->query()->whereBetween('duration', [5, 30])->count(),  // 5-30s
            'very_slow' => $this->getModel()->query()->where('duration', '>', 30)->count(),    // > 30s
        ];
    }

    public function cleanup()
    {
        return $this->getModel()->query()->delete();
    }
}
