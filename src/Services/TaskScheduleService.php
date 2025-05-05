<?php

namespace DagaSmart\TaskSchedule\Services;

use DagaSmart\BizAdmin\Admin;
use Illuminate\Database\Query\Builder;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
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

    public function sortable($query)
    {
        if (request()->orderBy) {
            parent::sortable($query);
        } else {
            $query->orderBy($this->primaryKey());
        }
    }

    /**
     * 获取任务分组
     * @return array|mixed
     */
    public function getGroups()
    {
        $data = $this->getModel()->getGroups()->toArray();
        return array_column($data,'group_name','id');
    }

    /**
     * 状态
     * @return array
     */
    public function statusOption()
    {
        return $this->getModel()->statusOption();
    }


    /**
     * 保存前
     * 操作人信息
     * @param $data
     * @param $primaryKey
     * @return void
     */
    public function saving(&$data, $primaryKey = '')
    {
        $admin = admin_user();
        admin_abort_if(!$admin, '请先登录');

        $data['oper_id'] = $admin->id;
        $data['oper_as'] = $admin->name;
    }
}
