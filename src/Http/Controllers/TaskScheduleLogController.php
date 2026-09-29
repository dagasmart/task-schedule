<?php

namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleLogService;

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
                            ['label' => '运行中', 'value' => 'running'],
                            ['label' => '成功', 'value' => 'success'],
                            ['label' => '失败', 'value' => 'failed'],
                        ])
                        ->clearable()
                        ->size('sm'),
                    amis()->DatetimeRangeControl('created_at', '执行时间')
                        ->clearable()
                        ->size('sm'),
                ])
            )
            ->headerToolbar([
                amis()->ReloadAction()->label('刷新'),
            ])
            ->autoFillHeight(true)
            ->columns([
                amis()->TableColumn('id', 'ID')->sortable()->width(60),
                amis()->TableColumn('task_name', '任务名称')->width(150),
                amis()->TableColumn('command', '执行命令')->width(250)->set('textOverflow', 'ellipsis'),
                amis()->TableColumn('status', '状态')->width(80)
                    ->set('type', 'mapping')
                    ->set('map', [
                        'running' => '<span class="label label-info">运行中</span>',
                        'success' => '<span class="label label-success">成功</span>',
                        'failed'  => '<span class="label label-danger">失败</span>',
                    ]),
                amis()->TableColumn('duration', '耗时(秒)')->width(80),
                amis()->TableColumn('exit_code', '退出码')->width(70),
                amis()->TableColumn('output', '输出信息')->width(200)->set('textOverflow', 'ellipsis'),
                amis()->TableColumn('started_at', '开始时间')->type('datetime')->width(150),
                amis()->TableColumn('finished_at', '结束时间')->type('datetime')->width(150),
                amis()->TableColumn('created_at', '记录时间')->type('datetime')->width(150),
            ]);

        return $this->baseList($crud);
    }

    public function index()
    {
        $query = $this->service->getQuery();
        $list = $this->service->getLogList($query);
        return $this->response()->success($list);
    }
}