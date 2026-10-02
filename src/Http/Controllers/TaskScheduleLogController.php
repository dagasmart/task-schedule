<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleLogService;
use DagaSmart\TaskSchedule\Enums\TaskStatus;

class TaskScheduleLogController extends AdminController
{
    protected string $serviceName = TaskScheduleLogService::class;

    public function list(): Page
    {
        $crud = $this->baseCRUD()
            ->filterTogglable(true)
            ->filter(
                $this->baseFilter()->body([
                    amis()->TextControl('task_name', '任务名称')->clearable()->size('sm'),
                    amis()->SelectControl('status', '执行状态')
                        ->options([
                            ['label' => '运行中', 'value' => TaskStatus::RUNNING->value],
                            ['label' => '成功', 'value' => TaskStatus::SUCCESS->value],
                            ['label' => '失败', 'value' => TaskStatus::FAILED->value],
                            ['label' => '超时', 'value' => TaskStatus::TIMEOUT->value],
                        ])
                        ->clearable()
                        ->size('sm'),
                    amis()->InputDatetimeRange('created_at', '执行时间')
                        ->clearable()
                        ->size('sm'),
                ])
            )
            ->headerToolbar([
                amis()->ReloadAction()->label('刷新'),
                amis()->AjaxAction()->label('清理日志')
                    ->api('POST /admin/task-schedule/logs/cleanup')
                    ->confirmText('确定要清理30天前的日志吗？'),
            ])
            ->autoFillHeight(true)
            ->columns([
                amis()->TableColumn('id', 'ID')->sortable()->width(60)->fixed('left'),
                amis()->TableColumn('task_name', '任务名称')->width(150)->fixed('left'),
                amis()->TableColumn('command', '执行命令')->width(250)->set('textOverflow', 'ellipsis'),
                amis()->TableColumn('status', '状态')->width(80)
                    ->set('type', 'mapping')
                    ->set('map', [
                        '1' => '<span class="label label-info">运行中</span>',
                        '2' => '<span class="label label-success">成功</span>',
                        '3' => '<span class="label label-danger">失败</span>',
                        '4' => '<span class="label label-warning">超时</span>',
                        '5' => '<span class="label label-default">已取消</span>',
                    ]),
                amis()->TableColumn('exit_code', '退出码')->width(70),
                amis()->TableColumn('duration', '耗时(秒)')->width(100),
                amis()->TableColumn('output', '输出信息')->width(200)->set('textOverflow', 'ellipsis'),
                amis()->TableColumn('worker_id', 'Worker')->width(120),
                amis()->TableColumn('started_at', '开始时间')->type('datetime')->width(150),
                amis()->TableColumn('finished_at', '结束时间')->type('datetime')->width(150),
                amis()->TableColumn('created_at', '记录时间')->type('datetime')->width(150),
            ]);

        return $this->baseList($crud);
    }
}
