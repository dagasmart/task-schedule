<?php

namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleGroupService;
use DagaSmart\BizAdmin\Controllers\AdminController;

/**
 * 任务调度表
 *
 * @property TaskScheduleGroupService $service
 */
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
//                    amis()->Divider(),
//                    amis()->CheckboxesControl('module', '模块')
//                        ->options($this->service->moduleOption())
//                        ->clearable()
//                        ->size('sm'),
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
				amis()->TableColumn('description', '分组描述')->width(300),
				amis()->TableColumn('sort', '排序[0-255]'),
//				amis()->TableColumn('module', '模块')->width(150),
				amis()->TableColumn('created_at', admin_trans('admin.created_at'))->type('datetime')->sortable(),
				amis()->TableColumn('updated_at', admin_trans('admin.updated_at'))->type('datetime')->sortable(),
				$this->rowActions('drawer', 'sm')->set('width', 180)->fixed('right')
			]);

		return $this->baseList($crud);
	}

	public function form($isEdit = false): Form
    {
		return $this->baseForm()->mode('normal')->body([
            amis()->GroupControl()->direction('vertical')->body([
                amis()->TextControl('group_name', '分组名称')
                    ->labelClassName('font-bold text-secondary')
                    ->required(),
                amis()->TextareaControl('description', '分组描述')->labelClassName('font-bold text-secondary'),
//                amis()->CheckboxesControl('module', '模块')
//                    ->className('rounded-xl border border-solid p-5 shadow')
//                    ->style(['border-color' => 'var(--button-enhance-default-top-border-color)'])
//                    ->options($this->service->moduleOption()),
                amis()->NumberControl('sort', '排序[0-255]')
                    ->labelClassName('font-bold text-secondary')
                    ->value(10)
                    ->size('sm')
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
//            amis()->CheckboxesControl('module', '模块')
//                ->options($this->service->moduleOption())
//                ->disabled(),
            amis()->NumberControl('sort', '排序[0-255]')->labelClassName('font-bold text-secondary')->static(),
			amis()->TextControl('created_at', admin_trans('admin.created_at'))->labelClassName('font-bold text-secondary')->static(),
			amis()->TextControl('updated_at', admin_trans('admin.updated_at'))->labelClassName('font-bold text-secondary')->static(),
		]);
	}
}
