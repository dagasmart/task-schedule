<?php

namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel as Model;
use DagaSmart\BizAdmin\Traits\CommonTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * 任务分组模型
 */
class TaskScheduleGroup extends Model
{
    use CommonTrait;

    public $table = 'task_schedule_group';

    protected $primaryKey = 'id';

    const STATUS_ACTIVE = ['success', 'danger', 'warning', 'info'];

    protected $fillable = [
        'group_name', 'description', 'sort', 'parent_id', 'module',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * 父分组
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 子分组
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * 分组下的任务
     */
    public function tasks()
    {
        return $this->hasMany(TaskSchedule::class, 'group_id');
    }

    /**
     * 仅查询激活的分组
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
