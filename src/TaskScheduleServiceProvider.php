<?php

namespace Dagasmart\TaskSchedule;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\TextControl;
use DagaSmart\BizAdmin\Extend\ServiceProvider;





class TaskScheduleServiceProvider extends ServiceProvider
{
    protected $menu = [
        [
            'parent' => NULL,
            'title' => '任务调度',
            'url' => '/schedule',
            'url_type' => 1,
            'icon' => 'carbon:event-schedule',
        ],
        [
            'parent' => '任务调度',
            'title' => '任务分组',
            'url' => '/task-schedule/group',
            'url_type' => 1,
            'icon' => 'mdi-light:folder-multiple',
        ],
        [
            'parent' => '任务调度',
            'title' => '任务列表',
            'url' => '/task-schedule/index',
            'url_type' => 1,
            'icon' => 'mdi-light:console',
        ],
        [
            'parent' => '任务调度',
            'title' => '统计分析',
            'url' => '/task-schedule/stat',
            'url_type' => 1,
            'icon' => 'mdi-light:signal',
        ],
    ];

	public function settingForm(): Form
    {
	    return $this->baseSettingForm()->body([
            TextControl::make()->name('value')->label('Value')->required(),
	    ]);
	}

}
