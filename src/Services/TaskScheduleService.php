<?php

namespace Dagasmart\TaskSchedule\Services;

use Illuminate\Database\Query\Builder;
use Dagasmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\BizAdmin\Services\AdminService;

/**
 * 任务调度表
 *
 * @method TaskSchedule getModel()
 * @method TaskSchedule|Builder query()
 */
class TaskScheduleService extends AdminService
{
	protected string $modelName = TaskSchedule::class;
}
