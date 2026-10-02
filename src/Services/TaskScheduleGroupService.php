<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Services;

use Illuminate\Database\Eloquent\Builder;
use DagaSmart\TaskSchedule\Models\TaskScheduleGroup;

/**
 * 任务分组服务类
 */
class TaskScheduleGroupService extends AdminService
{
    protected string $modelName = TaskScheduleGroup::class;

    public function sortable($query)
    {
        if (request()->orderBy) {
            parent::sortable($query);
        } else {
            $query->orderBy('sort', 'asc')->orderBy($this->primaryKey());
        }
    }

    /**
     * 保存前处理
     */
    public function saving(&$data, $primaryKey = ''): void
    {
        parent::saving($data, $primaryKey);
    }

    /**
     * 删除前检查
     */
    public function deleting($model): void
    {
        // 检查是否有子分组
        $childCount = $model->children()->count();
        if ($childCount > 0) {
            admin_abort("该分组下有 {$childCount} 个子分组，无法删除");
        }

        // 检查是否有任务
        $taskCount = $model->tasks()->count();
        if ($taskCount > 0) {
            admin_abort("该分组下有 {$taskCount} 个任务，无法删除");
        }
    }

    public function treeOption(): array
    {
        $list = $this->getModel()
            ->select(['id', 'id as value', 'group_name as label', 'parent_id'])
            ->orderBy('sort')
            ->get()
            ->toArray();

        // 转树形
        return array2tree($list, 0);
    }
}
