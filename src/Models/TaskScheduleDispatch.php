<?php

namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 任务分发记录模型
 *
 * 用于分布式环境下的任务调度追踪：
 * - 防重复执行（PostgreSQL Advisory Lock）
 * - 单服务器执行（onOneServer）
 * - 执行状态追踪
 */
class TaskScheduleDispatch extends Model
{
    public $table = 'task_schedule_dispatch';

    protected $fillable = [
        'task_id', 'dispatch_id', 'status', 'worker_id', 'server_id',
        'scheduled_at', 'started_at', 'finished_at', 'lock_key',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'lock_key' => 'integer',
    ];

    /**
     * 关联任务
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskSchedule::class, 'task_id');
    }

    /**
     * 查询正在执行的任务
     */
    public function scopeExecuting(Builder $query): Builder
    {
        return $query->whereIn('status', [
            \DagaSmart\TaskSchedule\Enums\TaskStatus::RUNNING->value,
            \DagaSmart\TaskSchedule\Enums\TaskStatus::PENDING->value,
        ]);
    }

    /**
     * 查询超时的任务
     */
    public function scopeTimedOut(Builder $query, int $minutes = 60): Builder
    {
        return $query->executing()
            ->where('started_at', '<', now()->subMinutes($minutes));
    }
}
