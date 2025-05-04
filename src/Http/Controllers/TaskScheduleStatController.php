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
class TaskScheduleStatController extends AdminController
{
	protected string $serviceName = TaskScheduleGroupService::class;

	public function list(): Page
    {
		$crud = $this->baseCRUD()
			->filterTogglable(false)
			->headerToolbar([
				$this->createButton('drawer'),
				...$this->baseHeaderToolBar()
			])
            ->autoFillHeight(true)
			->columns([
				amis()->TableColumn('id', 'ID')->sortable()->fixed('left'),
				amis()->TableColumn('task_name', '任务名称')->width(200)->fixed('left'),
				amis()->TableColumn('command', '任务命令')->width(300),
				amis()->TableColumn('parameters', '执行参数'),
				amis()->TableColumn('expression', '执行时间')->width(150),
				amis()->TableColumn('active', '任务状态'),
				amis()->TableColumn('timezone', '时区'),
				amis()->TableColumn('environments', '环境设置'),
				amis()->TableColumn('without_overlapping', '是否重复执行'),
				amis()->TableColumn('on_one_server', '是否当前服务器'),
				amis()->TableColumn('in_background', '是否后台运行'),
				amis()->TableColumn('in_maintenance_mode', '是否维护模式'),
				amis()->TableColumn('output_file_path', '输出的文件路径'),
				amis()->TableColumn('output_append', '输出追加'),
				amis()->TableColumn('output_email', '输出发送邮件'),
				amis()->TableColumn('output_email_on_failure', '失败发送邮件'),
				amis()->TableColumn('created_at', admin_trans('admin.created_at'))->type('datetime')->sortable(),
				amis()->TableColumn('updated_at', admin_trans('admin.updated_at'))->type('datetime')->sortable(),
				$this->rowActions('drawer')->set('width',180)->fixed('right')
			]);

		return $this->baseList($crud);
	}

	public function form($isEdit = false): Form
    {
		return $this->baseForm()->mode('normal')->body([
            amis()->Tabs()->tabsMode('chrome')->className('rounded')->tabs([
                // 字段信息
                amis()->Tab()->title('基本信息')->body([
                    amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->body([
                            amis()->TextControl('task_name', '任务名称'),
                            amis()->TextareaControl('command', '任务命令'),
                            amis()->TextareaControl('parameters', '执行参数'),
                            amis()->TextControl('expression', '执行时间，cron格式：*/1 * * * *'),
                            amis()->SwitchControl('active', '任务状态'),
                        ]),
                    ]),
                ]),
                // 字段信息
                amis()->Tab()->title('任务描述')->body([
                    amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->body([
                            amis()->TextareaControl('description', '任务描述'),
                            amis()->SelectControl('timezone', '时区')
                                ->options(timezone_identifiers_list())
                                ->value(date_default_timezone_get())
                                ->searchable(),
                            amis()->RadiosControl('environments', '环境设置')
                                ->options(['windows', 'linux'])->value('linux'),
                            amis()->SwitchControl('without_overlapping', '是否重复执行'),
                            amis()->SwitchControl('on_one_server', '是否当前服务器'),
                        ]),
                    ]),
                ]),
                // 字段信息
                amis()->Tab()->title('运维信息')->body([
                    amis()->Card()->body([
                        amis()->GroupControl()->direction('vertical')->body([
                            amis()->SwitchControl('in_background', '是否后台运行'),
                            amis()->SwitchControl('in_maintenance_mode', '是否维护模式'),
                            amis()->TextControl('output_file_path', '输出的文件路径'),
                            amis()->SwitchControl('output_append', '输出追加'),
                            amis()->TextControl('output_email', '输出发送邮件'),
                            amis()->SwitchControl('output_email_on_failure', '失败发送邮件'),
                        ]),
                    ]),
                ]),
            ])
		]);
	}

	public function detail(): Form
    {
		return $this->baseDetail()->body([
			amis()->TextControl('id', 'ID')->static(),
			amis()->TextControl('description', '任务描述')->static(),
			amis()->TextControl('command', '任务命令')->static(),
			amis()->TextControl('parameters', '执行参数')->static(),
			amis()->TextControl('expression', '执行时间')->static(),
			amis()->TextControl('active', '任务状态')->static(),
			amis()->TextControl('timezone', '时区')->static(),
			amis()->TextControl('environments', '环境设置')->static(),
			amis()->TextControl('without_overlapping', '是否重复执行')->static(),
			amis()->TextControl('on_one_server', '是否当前服务器')->static(),
			amis()->TextControl('in_background', '是否后台运行')->static(),
			amis()->TextControl('in_maintenance_mode', '是否维护模式')->static(),
			amis()->TextControl('output_file_path', '输出的文件路径')->static(),
			amis()->TextControl('output_append', '输出追加')->static(),
			amis()->TextControl('output_email', '输出发送邮件')->static(),
			amis()->TextControl('output_email_on_failure', '失败发送邮件')->static(),
			amis()->TextControl('created_at', admin_trans('admin.created_at'))->static(),
			amis()->TextControl('updated_at', admin_trans('admin.updated_at'))->static(),
		]);
	}
}
