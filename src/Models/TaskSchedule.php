<?php

namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel as Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * 任务调度表
 */
class TaskSchedule extends Model
{
    public $table = 'task_schedule';

    protected $fillable = [
        'task_name', 'description', 'command', 'parameters', 'expression', 'active', 'timezone',
        'environments', 'without_overlapping', 'on_one_server', 'in_background', 'in_maintenance_mode',
        'output_file_path', 'output_append', 'output_email', 'output_email_on_failure',
        'group_id', 'module', 'oper_id', 'oper_as',
    ];

    protected $casts = [
        'active' => 'boolean',
        'on_one_server' => 'boolean',
        'in_background' => 'boolean',
        'in_maintenance_mode' => 'boolean',
        'output_append' => 'boolean',
        'output_email_on_failure' => 'boolean',
    ];

    /**
     * Scope a query to only include active schedule.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

}
