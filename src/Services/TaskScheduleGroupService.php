<?php

namespace DagaSmart\TaskSchedule\Services;

use Illuminate\Database\Query\Builder;
use DagaSmart\TaskSchedule\Models\TaskScheduleGroup;
use DagaSmart\BizAdmin\Services\AdminService;

/**
 * 任务调度表
 *
 * @method TaskScheduleGroup getModel()
 * @method TaskScheduleGroup|Builder query()
 */
class TaskScheduleGroupService extends AdminService
{
	protected string $modelName = TaskScheduleGroup::class;
}
