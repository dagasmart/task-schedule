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

    protected $appends = ['group_as'];

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

    public function getGroupAsAttribute()
    {
        return TaskScheduleGroup::query()->where('id', $this->group_id)->value('group_name');
    }

    /**
     * 获取任务分组
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGroups()
    {
        return TaskScheduleGroup::query()->get(['id', 'group_name']);
    }

    /**
     * 状态
     * @return array
     */
    public function statusOption()
    {
        return [
            ['label' => '暂停下线', 'value' => 0],
            ['label' => '正常上线', 'value' => 1]
        ];
    }

}
