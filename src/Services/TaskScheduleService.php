<?php

namespace DagaSmart\TaskSchedule\Services;

use Cron\CronExpression;
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
        return array2tree($data);
    }

    /**
     * 环境列表
     * @return array
     */
    public function envOption()
    {
        return ['local' => '本地环境', 'development' => '开发环境', 'testing' => '测试环境', 'production' => '生产环境'];
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

        // ✅ cron 校验
        if (!empty($data['expression'])) {
            if (!CronExpression::isValidExpression($data['expression'])) {
                admin_abort('执行时间不是合法的 CRON 表达式');
            }
        }

        $data['creator_id'] = $admin->id;
        $data['creator'] = $admin->name;
    }

    /**
     * 立即执行
     * @param $id
     * @return bool
     */
    public function execute($id): bool
    {
        $task = $this->getModel()->find($id);
        admin_abort_if(!$task, '此项任务不存在');

        $command = trim($task->command);
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        // 把参数按空格拆成数组（简单处理）
        $params = [];
        if (!empty($task->parameters)) {
            $params = preg_split('/\s+/', $task->parameters);
        }

        try {
            $exitCode = Artisan::call($command, $params);
            return $exitCode === 0;
        } catch (\Throwable $e) {
            admin_abort('执行失败: ' . $e->getMessage());
        }
    }


}
