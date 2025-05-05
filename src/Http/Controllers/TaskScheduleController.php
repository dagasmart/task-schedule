<?php

namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleService;
use DagaSmart\BizAdmin\Controllers\AdminController;

/**
 * 任务调度表
 *
 * @property TaskScheduleService $service
 */
class TaskScheduleController extends AdminController
{
	protected string $serviceName = TaskScheduleService::class;

	public function list(): Page
    {
		$crud = $this->baseCRUD()
            ->filterTogglable(true)
            ->filter(
                $this->baseFilter()->body([
                    amis()->TextControl('task_name', '任务名称')
                        ->clearable()
                        ->size('sm'),
                    amis()->SelectControl('active', '任务状态')
                        ->options($this->service->statusOption())
                        ->multiple()
                        ->checkAll()
                        ->clearable()
                        ->size(),
                    amis()->Divider(),
                    amis()->CheckboxesControl('group_id', '任务分组')
                        ->options($this->service->getGroups())
                        ->clearable()
                        ->size(),
                ])
            )
            ->headerToolbar([
                $this->createButton('drawer'),
                ...$this->baseHeaderToolBar()
            ])
            ->autoFillHeight(true)
			->columns([
				amis()->TableColumn('id', 'ID')->sortable()->fixed('left'),
				amis()->TableColumn('task_name', '任务名称')->width(200)
                    ->searchable()
                    ->fixed('left'),
                amis()->TableColumn('group_as', '任务分组')
                    ->searchable(['name' => 'group_id', 'type'=>'checkboxes', 'options'=>$this->service->getGroups(), 'size'=>'sm'])
                    ->width(100),
				amis()->TableColumn('command', '任务命令')->width(300),
				amis()->TableColumn('parameters', '执行参数'),
				amis()->TableColumn('expression', '执行时间')->width(150),
                amis()->TableColumn('active', '任务状态')
                    ->searchable(['type'=>'checkboxes', 'options'=>$this->service->statusOption(), 'size'=>'sm'])
                    ->set('type','switch'),
				amis()->TableColumn('timezone', '时区'),
				amis()->TableColumn('environments', '环境设置'),
				amis()->TableColumn('without_overlapping', '是否重复执行')->set('type','switch'),
				amis()->TableColumn('on_one_server', '是否当前服务器')->set('type','switch'),
				amis()->TableColumn('in_background', '是否后台运行')->set('type','switch'),
				amis()->TableColumn('in_maintenance_mode', '是否维护模式')->set('type','switch'),
				amis()->TableColumn('output_file_path', '输出的文件路径'),
				amis()->TableColumn('output_append', '输出追加')->set('type','switch'),
				amis()->TableColumn('output_email', '输出发送邮件'),
				amis()->TableColumn('output_email_on_failure', '失败发送邮件')->set('type','switch'),
				amis()->TableColumn('created_at', admin_trans('admin.created_at'))->type('datetime')->sortable(),
				amis()->TableColumn('updated_at', admin_trans('admin.updated_at'))->type('datetime')->sortable(),
				$this->rowActions([
                    $this->rowShowButton('drawer'),
                    $this->rowEditButton('drawer'),
                    $this->rowDeleteButton(),
                ])->set('width',180)->fixed('right')
			]);

		return $this->baseList($crud);
	}

	public function form($isEdit = false): Form
    {
		return $this->baseForm()->mode('normal')->body([
            amis()->Tabs()->tabsMode('chrome')->className('rounded')->tabs([
                // 字段信息
                amis()->Tab()->title('基本信息')->body([
                    //amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                            amis()->SelectControl('group_id', '任务分组')
                                ->options($this->service->getGroups())
                                ->labelClassName('font-bold text-secondary')
                                ->required(),
                            amis()->TextControl('task_name', '任务名称')
                                ->labelClassName('font-bold text-secondary')
                                ->required(),
                            amis()->TextareaControl('command', '任务命令')
                                ->labelClassName('font-bold text-secondary')
                                ->required(),
                            amis()->TextControl('parameters', '执行参数')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->TextControl('expression', '执行时间，cron格式：*/1 * * * *')
                                ->labelClassName('font-bold text-secondary')
                                ->required(),
                            amis()->SwitchControl('active', '任务状态')
                                ->onText('正常上线')->offText('暂停下线')
                                ->labelClassName('font-bold text-secondary'),
                        ]),
                    //]),
                ]),
                // 字段信息
                amis()->Tab()->title('任务描述')->body([
                    //amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                            amis()->TextareaControl('description', '任务描述')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SelectControl('timezone', '时区')
                                ->options(timezone_identifiers_list())
                                ->value(date_default_timezone_get())
                                ->labelClassName('font-bold text-secondary')
                                ->searchable(),
                            amis()->RadiosControl('environments', '环境设置')
                                ->options(['windows', 'linux'])
                                ->value('linux')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SwitchControl('without_overlapping', '是否重复执行')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SwitchControl('on_one_server', '是否当前服务器')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                        ]),
                    //]),
                ]),
                // 字段信息
                amis()->Tab()->title('运维信息')->body([
                    //amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                            amis()->SwitchControl('in_background', '是否后台运行')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SwitchControl('in_maintenance_mode', '是否维护模式')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->TextControl('output_file_path', '输出的文件路径')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SwitchControl('output_append', '输出追加')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->TextControl('output_email', '输出发送邮件')
                                ->labelClassName('font-bold text-secondary'),
                            amis()->SwitchControl('output_email_on_failure', '失败发送邮件')
                                ->onText('是')->offText('否')
                                ->labelClassName('font-bold text-secondary'),
                        ]),
                    //]),
                ]),
            ])
		]);
	}

	public function detail(): Form
    {
		return $this->baseDetail()->body([
            amis()->Tabs()->tabsMode('chrome')->className('rounded')->tabs([
                amis()->Tab()->title('基本信息')->body([
                    amis()->TextControl('id', 'ID')->static(),
                    amis()->TextControl('task_name', '任务名称')->static(),
                    amis()->TextControl('group_as', '任务分组')->static(),
                    amis()->TextControl('command', '任务命令')->static(),
                    amis()->TextControl('parameters', '执行参数')->static(),
                    amis()->TextControl('expression', '执行时间')->static(),
                    amis()->TextControl('description', '任务描述')->static(),
                    amis()->SwitchControl('active', '任务状态')->onText('正常上线')->offText('暂停下线')->disabled(),
                    amis()->TextControl('timezone', '时区')->static(),
                    amis()->TextControl('environments', '环境设置')->static(),
                ]),
                amis()->Tab()->title('运维信息')->body([
                    amis()->SwitchControl('without_overlapping', '是否重复执行')->onText('是')->offText('否')->disabled(),
                    amis()->SwitchControl('on_one_server', '是否当前服务器')->onText('是')->offText('否')->disabled(),
                    amis()->SwitchControl('in_background', '是否后台运行')->onText('是')->offText('否')->disabled(),
                    amis()->SwitchControl('in_maintenance_mode', '是否维护模式')->onText('是')->offText('否')->disabled(),
                    amis()->TextControl('output_file_path', '输出的文件路径')->static(),
                    amis()->SwitchControl('output_append', '输出追加')->onText('是')->offText('否')->disabled(),
                    amis()->TextControl('output_email', '输出发送邮件')->static(),
                    amis()->SwitchControl('output_email_on_failure', '失败发送邮件')->onText('是')->offText('否')->disabled(),
                    amis()->TextControl('created_at', admin_trans('admin.created_at'))->static(),
                    amis()->TextControl('updated_at', admin_trans('admin.updated_at'))->static(),
                ]),
            ]),
		]);
	}
}
