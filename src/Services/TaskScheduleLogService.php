<?php

namespace DagaSmart\TaskSchedule\Services;

use Illuminate\Database\Query\Builder;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;

/**
 * 任务调度表
 *
 * @method TaskScheduleLog getModel()
 * @method TaskScheduleLog|Builder query()
 */
class TaskScheduleLogService extends AdminService
{
    protected string $modelName = TaskScheduleLog::class;

}
