<?php

namespace DagaSmart\TaskSchedule\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * 任务调度日志表
 */
class TaskScheduleLog extends Model
{
    public $table = 'task_schedule_log';

    protected $fillable = [
        'task_id', 'description', 'state', 'result', 'module',
    ];

    protected $casts = [
        'state' => 'boolean',
    ];

    /**
     * Scope a query to only include active schedule.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 1);
    }

}
