<?php

namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel as Model;
use DagaSmart\BizAdmin\Traits\CommonTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * 任务调度表
 */
class TaskScheduleGroup extends Model
{
    use CommonTrait;
    public $table = 'task_schedule_group';

    protected $primaryKey = 'id';

    CONST STATUSACTIVE = ['success', 'danger', 'warning', 'info'];

    protected $fillable = [
        'group_name', 'description', 'sort', 'module',
    ];

    protected $casts = [
        'active' => 'boolean',
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

    /**
     * 项目子模块
     * @return array
     */
    public function moduleOption()
    {
        $options = [];
        if ($modules = $this->getModules()) {
            $i = 0;
            array_walk($modules, function (&$value, $key) use (&$i, &$options) {
                $options[$i]['label'] = $key;
                $options[$i]['value'] = $key;
                $options[$i]['klass'] = static::STATUSACTIVE[$i];
                $i ++;
            });
        }
        return $options;
    }

}
