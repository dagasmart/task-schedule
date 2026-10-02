<?php

namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleGroupService;

class TaskScheduleGroupController extends AdminController
{
    protected string $serviceName = TaskScheduleGroupService::class;

    public function list(): Page
    {
        $crud = $this->baseCRUD()
            ->filterTogglable(true)
            ->filter(
                $this->baseFilter()->body([
                    amis()->TextControl('group_name', '分组名称')
                        ->labelClassName('font-bold text-secondary')
                        ->clearable()
                        ->size('sm'),
                ])
            )
            ->headerToolbar([
                $this->createButton('drawer', 'sm'),
                ...$this->baseHeaderToolBar()
            ])
            ->autoFillHeight(true)
            ->columns([
                amis()->TableColumn('id', 'ID')->sortable()->fixed('left'),
                amis()->TableColumn('group_name', '分组名称')->width(200)->fixed('left'),
                amis()->TableColumn('parent_id', '上级分组')->width(150)
                    ->set('type', 'tree-select')
                    ->set('options', $this->service->treeOption())
                    ->set('labelField', 'level_name')
                    ->set('textOverflow', 'noWrap')
                    ->set('static', true),
                amis()->TableColumn('description', '分组描述')->width(300),
                amis()->TableColumn('sort', '排序[0-32767]')->width(120),
                amis()->TableColumn('created_at', '创建时间')->type('datetime')->sortable(),
                amis()->TableColumn('updated_at', '更新时间')->type('datetime')->sortable(),
                $this->rowActions('drawer', 'sm')->set('width', 180)->fixed('right')
            ]);

        return $this->baseList($crud);
    }

    public function form($isEdit = false): Form
    {
        return $this->baseForm()->mode('normal')->body([
            amis()->GroupControl()->direction('vertical')->body([
                amis()->TreeSelectControl('parent_id', '上级分组')
                    ->source(admin_url('task-schedule/group/treeOption'))
                    ->onlyLeaf(false)
                    ->searchable()
                    ->clearable()
                    ->value()
                    ->description('不选则为顶级分组'),
                amis()->TextControl('group_name', '分组名称')
                    ->labelClassName('font-bold text-secondary')
                    ->required(),
                amis()->TextareaControl('description', '分组描述')
                    ->labelClassName('font-bold text-secondary'),
                amis()->NumberControl('sort', '排序[0-32767]')
                    ->labelClassName('font-bold text-secondary')
                    ->value(10)
                    ->min(0)->max(32767)
                    ->required(),
            ]),
        ]);
    }

    public function detail(): Form
    {
        return $this->baseDetail()->body([
            amis()->TextControl('id', 'ID')->labelClassName('font-bold text-secondary')->static(),
            amis()->TextControl('group_name', '分组名称')->labelClassName('font-bold text-secondary')->static(),
            amis()->TextareaControl('description', '分组描述')->labelClassName('font-bold text-secondary')->static(),
            amis()->NumberControl('sort', '排序[0-32767]')->labelClassName('font-bold text-secondary')->static(),
            amis()->TextControl('created_at', '创建时间')->labelClassName('font-bold text-secondary')->static(),
            amis()->TextControl('updated_at', '更新时间')->labelClassName('font-bold text-secondary')->static(),
        ]);
    }

    public function treeOption(): array
    {
        return $this->service->treeOption();
    }

}
