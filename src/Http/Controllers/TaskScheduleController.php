<?php

namespace Dagasmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\Page;
use Dagasmart\TaskSchedule\Services\TaskScheduleService;
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
			->filterTogglable(false)
			->headerToolbar([
				$this->createButton('drawer'),
				...$this->baseHeaderToolBar()
			])
            ->autoFillHeight(true)
			->columns([
				amis()->TableColumn('id', 'ID')->sortable()->fixed('left'),
				amis()->TableColumn('description', '任务描述')->width(200)->fixed('left'),
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
			amis()->TextControl('description', '任务描述'),
			amis()->TextControl('command', '任务命令'),
			amis()->TextControl('parameters', '执行参数'),
			amis()->TextControl('expression', '执行时间'),
			amis()->SwitchControl('active', '任务状态'),
			amis()->TextControl('timezone', '时区'),
			amis()->TextControl('environments', '环境设置'),
			amis()->TextControl('without_overlapping', '是否重复执行'),
			amis()->SwitchControl('on_one_server', '是否当前服务器'),
			amis()->SwitchControl('in_background', '是否后台运行'),
			amis()->SwitchControl('in_maintenance_mode', '是否维护模式'),
			amis()->TextControl('output_file_path', '输出的文件路径'),
			amis()->TextControl('output_append', '输出追加'),
			amis()->TextControl('output_email', '输出发送邮件'),
			amis()->TextControl('output_email_on_failure', '失败发送邮件'),
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
