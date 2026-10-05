<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Renderers\Form;
use DagaSmart\BizAdmin\Renderers\Page;
use DagaSmart\TaskSchedule\Services\TaskScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;
use DagaSmart\TaskSchedule\Enums\TaskState;

/**
 * 任务调度控制器
 */
class TaskScheduleController extends AdminController
{
    protected string $serviceName = TaskScheduleService::class;

    /**
     * 列表页
     */
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
                        ->options($this->service->stateOption())
                        ->multiple()
                        ->checkAll()
                        ->clearable()
                        ->size('sm'),
                    amis()->Divider(),
                    amis()->CheckboxesControl('group_id', '任务分组')
                        ->options($this->service->getGroups())
                        ->clearable()
                        ->size('sm'),
                    amis()->SelectControl('precision', '调度精度')
                        ->options($this->service->precisionOption())
                        ->clearable()
                        ->size('sm'),
                ])
            )
            ->headerToolbar([
                $this->createButton('drawer'),
                ...$this->baseHeaderToolBar()
            ])
            ->autoFillHeight(true)
            ->columns([
                amis()->TableColumn('id', 'ID')->sortable()->fixed('left'),
                amis()->TableColumn('task_name', '任务名称')->width(180)
                    ->searchable()
                    ->fixed('left'),
                amis()->TableColumn('group_id', '任务分组')
                    ->set('type', 'input-tag')
                    ->set('options', $this->service->getGroups())
                    ->set('labelField', 'level_name')
                    ->set('static', true)
                    ->width(120),
                amis()->TableColumn('task_type', '任务类型')
                    ->set('type', 'mapping')
                    ->set('map', $this->service->taskTypeMap())
                    ->width(80),
                amis()->TableColumn('command', '执行命令')->width(250)->set('textOverflow', 'ellipsis'),
                amis()->TableColumn('expression', 'Cron表达式')->width(130),
                amis()->TableColumn('precision', '精度')
                    ->set('type', 'mapping')
                    ->set('map', $this->service->precisionMap())
                    ->width(60),
                amis()->TableColumn('active', '状态')
                    ->set('type', 'switch')
                    ->width(80),
                amis()->TableColumn('last_run_at', '上次执行')->type('datetime')->width(150),
                amis()->TableColumn('next_run_at', '下次执行')->type('datetime')->width(150),
                amis()->TableColumn('without_overlapping', '防重叠')
                    ->set('type', 'switch')
                    ->width(80),
                amis()->TableColumn('created_at', '创建时间')->type('datetime')->sortable()->width(150),
                amis()->TableColumn('description', '任务描述')->width(300),
                $this->rowActions([
                    $this->rowShowButton('drawer'),
                    $this->rowEditButton('drawer'),
                    $this->rowDeleteButton(),
                    // 执行
                    amis()->LinkAction()->label('执行')
                        ->level('link')->className('text-primary')
                        ->confirmText('确认立即执行该任务？')
                        ->api('post:' . admin_url('task-schedule/${id}/execute')),
                    // 预览
                    amis()->LinkAction()->label('预览')
                        ->level('link')->className('text-success')
                        ->actionType('drawer')
                        ->drawer(
                            amis()->Drawer()
                                ->title('任务预览【<b class="text-danger">${task_name}</b>】')
                                ->closeOnEsc()
                                ->closeOnOutside()
                                ->data(['id' => '${id}', 'task_name' => '${task_name}'])
                                ->body(
                                    amis()->Service()->api(admin_url('task-schedule/${id}/preview'))
                                        ->interval(30000)
                                        ->silentPolling()
                                        ->body([
                                            amis()->Tabs()->tabsMode('strong')->tabs([
                                                amis()->Tab()->title('下次执行时间节点')->body([
                                                    amis()->Table()
                                                        ->source('${next_runs}')
                                                        ->columns([
                                                            amis()->TableColumn('id', 'ID'),
                                                            amis()->TableColumn('run_time', '执行时间')->type('datetime'),
                                                        ]),
                                                ]),
                                                amis()->Tab()->title('最后10次执行概况')->body([
                                                    amis()->Table()
                                                        ->source('${last_runs}')
                                                        ->columns([
                                                            amis()->TableColumn('id', 'ID'),
                                                            amis()->TableColumn('started_at', '开始时间')->type('datetime'),
                                                            amis()->TableColumn('finished_at', '结束时间')->type('datetime'),
                                                            amis()->TableColumn('duration', '耗时')
                                                                ->set('type', 'tpl')
                                                                ->set('tpl', '${duration}s'),
                                                            amis()->TableColumn('state', '状态')
                                                                ->set('type', 'mapping')
                                                                ->set('map', [
                                                                    '1' => ['label' => '⭕'],
                                                                    '2' => ['label' => '✅'],
                                                                    '3' => ['label' => '❌'],
                                                                ])
                                                        ]),
                                                ]),
                                                amis()->Tab()->title('运行分析')->body([
                                                    amis()->Grid()->columns([
                                                        amis()->Grid()->columns([
                                                            // ✅ 饼图：source 指向 pie_data
                                                            $this->pieChart('执行结果分布')
                                                                //->source('pie_data')
                                                                ->set('md', 12),
                                                            amis()->Divider(),
                                                            // ✅ 柱图：source 指向 bar_data
                                                            $this->barChart('每日执行趋势')
                                                                //->source('bar_data')
                                                                ->set('md', 12),
                                                            amis()->Divider(),
                                                            // ✅ 折线图：source 指向 trend_runs
                                                            $this->lineChart('每月运行趋势')
                                                                //->source('trend_runs')
                                                                ->set('md', 12),
                                                        ]),
                                                    ]),
                                                ]),
                                            ])
                                        ])
                                )
                        ),
                    // 日志
                    amis()->LinkAction()->label('日志')
                        ->level('link')->className('text-current')
                        ->linkTo(admin_url('task-schedule-logs?task_id=${id}')),
                ])->set('width', 150)->fixed('right')
            ]);

        return $this->baseList($crud);
    }

    /**
     * 表单
     */
    public function form($isEdit = false): Form
    {
        return $this->baseForm()->mode('normal')->data([
            'descMap' => [
                PrecisionLevel::SECOND->value => '6段(秒 分 时 日 月 周)，如：*/30 * * * * *',
                PrecisionLevel::MINUTE->value => '5段(分 时 日 月 周)，如：0-59 * * * *',
                PrecisionLevel::HOUR->value => '5段(分 时 日 月 周)，如：* 0-23 * * *',
                PrecisionLevel::DAY->value => '5段(分 时 日 月 周)，如：* * 1-31 * *',
                PrecisionLevel::WEEK->value => '5段(分 时 日 月 周)，如：* * * * 0-6',
                PrecisionLevel::MONTH->value => '5段(分 时 日 月 周)，如：* * * 1-12 *',
            ],
            'placeholderMap' => [
                PrecisionLevel::SECOND->value => '*/30 * * * * *',
                PrecisionLevel::MINUTE->value => '0-59 * * * *',
                PrecisionLevel::HOUR->value => '* 0-23 * * *',
                PrecisionLevel::DAY->value => '* * 1-31 * *',
                PrecisionLevel::WEEK->value => '* * * * 0-6',
                PrecisionLevel::MONTH->value => '* * * 1-12 *',
            ],
            '_expression' => '${expression}',
        ])->body([
            amis()->Tabs()->tabsMode('chrome')->className('rounded')->tabs([
                // 基本信息
                amis()->Tab()->title('基本信息')->body([
                    amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                        amis()->TreeSelectControl('group_id', '任务分组')
                            ->options($this->service->getGroups())
                            //->labelField('level_name')
                            ->labelClassName('font-bold text-secondary')
                            ->onlyLeaf()
                            ->required(),
                        amis()->TextControl('task_name', '任务名称')
                            ->labelClassName('font-bold text-secondary')
                            ->required(),
                        amis()->SelectControl('task_type', '任务类型')
                            ->options($this->service->taskTypeOption())
                            ->value('command')
                            ->required(),
                        amis()->TextareaControl('command', '执行命令/类名/URL')
                            ->labelClassName('font-bold text-secondary')
                            ->required()
                            ->description('命令如: cache:clear | 类名如: App\\Jobs\\ProcessOrder | URL如: https://api.example.com/webhook'),
                        amis()->TextControl('parameters', '执行参数')
                            ->labelClassName('font-bold text-secondary')
                            ->description('JSON数组格式，如: ["--force", "--verbose"]')
                            ->placeholder('["--force", "--verbose"]'),
                        amis()->TextareaControl('description', '任务描述')
                            ->description('任务场景的描述，255字以内'),
                    ]),
                ]),
                // 调度设置
                amis()->Tab()->title('调度设置')->body([
                    amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                        amis()->SelectControl('precision', '调度精度')
                            ->options($this->service->precisionOption())
                            ->value(PrecisionLevel::MINUTE->value)
                            ->clearable()
                            ->required()
                            ->labelClassName('font-bold text-secondary'),
                        amis()->TextControl('expression', 'Cron表达式（执行时间）')
                            ->labelClassName('font-bold text-secondary')
                            ->clearValueOnHidden(true)
                            ->description('${descMap[precision] || "请先选择调度精度"}')
                            ->placeholder('${placeholderMap[precision] || ""}')
//                            ->validations('matchRegexp')
//                            ->validationErrors([
//                                'matchRegexp' => '${precision == ' . PrecisionLevel::SECOND->value . ' ? "格式错误：需 6 段" : "格式错误：需 5 段"}',
//                            ])
                            ->disabledOn('${!precision}')
                            ->clearable()
                            ->required(),
                        amis()->SwitchControl('active', '任务状态')
                            ->onText('正常上线')->offText('暂停下线')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->NumberControl('priority', '优先级')
                            ->min(0)->max(100)->value(0)
                            ->description('数值越大优先级越高')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SelectControl('timezone', '时区')
                            ->options(timezone_identifiers_list())
                            ->value(date_default_timezone_get())
                            ->labelClassName('font-bold text-secondary')
                            ->searchable(),
                    ]),
                ]),
                // 高级设置
                amis()->Tab()->title('高级设置')->body([
                    amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                        amis()->TagControl('environments', '环境设置')
                            ->options($this->service->envOption())
                            ->value(\Illuminate\Support\Facades\App::environment())
                            ->labelClassName('font-bold text-secondary')
                            ->extractValue()
                            ->joinValues(false),
                        amis()->SwitchControl('without_overlapping', '防重复执行')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->NumberControl('overlap_release_minutes', '锁释放时间(分)')
                            ->min(1)->max(10080)->value(1440)
                            ->description('防重叠锁的最长持有时间')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SwitchControl('on_one_server', '单服务器执行')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SwitchControl('in_background', '后台运行')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SwitchControl('in_maintenance_mode', '维护模式执行')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->NumberControl('max_runtime', '最大运行时间(秒)')
                            ->min(0)->max(86400)->value(0)
                            ->description('0 = 不限制')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->NumberControl('retry_times', '重试次数')
                            ->min(0)->max(10)->value(0)
                            ->labelClassName('font-bold text-secondary'),
                        amis()->NumberControl('retry_interval', '重试间隔(秒)')
                            ->min(1)->max(3600)->value(60)
                            ->labelClassName('font-bold text-secondary'),
                    ]),
                ]),
                // 输出设置
                amis()->Tab()->title('输出设置')->body([
                    amis()->GroupControl()->direction('vertical')->className('p-5')->body([
                        amis()->TextControl('output_file_path', '输出文件路径')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SwitchControl('output_append', '追加输出')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->TextControl('output_email', '输出发送邮件')
                            ->labelClassName('font-bold text-secondary'),
                        amis()->SwitchControl('output_email_on_failure', '仅失败时发送')
                            ->onText('是')->offText('否')
                            ->labelClassName('font-bold text-secondary'),
                    ]),
                ]),
            ])
        ]);
    }

    /**
     * 详情页
     */
    public function detail(): Form
    {
        return $this->baseDetail()->body([
            amis()->Tabs()->tabsMode('chrome')->className('rounded')->tabs([
                amis()->Tab()->title('基本信息')->body([
                    amis()->TextControl('id', 'ID')->static(),
                    amis()->TextControl('task_name', '任务名称')->static(),
                    amis()->TextControl('task_type', '任务类型')
                        ->type('static-mapping')
                        ->set('map', $this->service->taskTypeMap())
                        ->static(),
                    amis()->TagControl('group_id', '任务分组')
                        ->options($this->service->getGroups())
                        ->labelField('level_name')
                        ->static(),
                    amis()->TextControl('command', '执行命令')->static(),
                    amis()->TextControl('expression', 'Cron表达式')->description('执行时间')->static(),
                    amis()->TextControl('precision', '精度级别')
                        ->type('static-mapping')
                        ->set('map', $this->service->precisionMap())
                        ->static(),
                    amis()->TextControl('last_run_at', '上次执行')->static(),
                    amis()->TextControl('next_run_at', '下次执行')->static(),
                ]),
                amis()->Tab()->title('高级信息')->body([
                    amis()->SwitchControl('without_overlapping', '防重复执行')->disabled(),
                    amis()->NumberControl('overlap_release_minutes', '锁释放时间')->disabled(),
                    amis()->SwitchControl('on_one_server', '单服务器执行')->disabled(),
                    amis()->SwitchControl('in_background', '后台运行')->disabled(),
                    amis()->SwitchControl('in_maintenance_mode', '维护模式执行')->disabled(),
                    amis()->NumberControl('max_runtime', '最大运行时间')->disabled(),
                    amis()->NumberControl('retry_times', '重试次数')->disabled(),
                    amis()->NumberControl('retry_interval', '重试间隔')->disabled(),
                    amis()->TextControl('created_at', '创建时间')->static(),
                    amis()->TextControl('updated_at', '更新时间')->static(),
                ]),
            ]),
        ]);
    }

    /**
     * 立即执行
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);

        $res = $this->service->execute($request->id);
        return $this->response()->successMessage('执行成功，稍候查看日志');
    }

    /**
     * 预览下次执行时间
     */
    public function preview($id = null)
    {
        admin_abort_if(!is_numeric($id), 'id不存在');

        return $this->service->previewRuns((int) $id, 10);
    }

    /**
     * 暂停任务
     */
    public function pause(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);

        $this->service->pause($request->id);
        return $this->response()->successMessage('任务已暂停');
    }

    /**
     * 恢复任务
     */
    public function resume(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);

        $this->service->resume($request->id);
        return $this->response()->successMessage('任务已恢复');
    }

    /**
     * 批量操作
     */
    public function batchAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:pause,resume,delete',
            'ids' => 'required|array',
        ]);

        $method = match ($request->action) {
            'pause' => 'batchPause',
            'resume' => 'batchResume',
            default => null,
        };

        if ($method) {
            $count = $this->service->$method($request->ids);
            return $this->response()->successMessage("已处理 {$count} 条记录");
        }

        // 批量删除
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $this->service->delete($id);
                $deleted++;
            } catch (\Throwable $e) {
                // 跳过错误
            }
        }

        return $this->response()->successMessage("已删除 {$deleted} 条记录");
    }

    /**
     * 执行结果分布 - 饼图
     */
    protected function pieChart(string $title = null)
    {
        $palette = app('theme')->echartsPalette();
        return amis()->Chart()
            ->height(280)
            ->config([
                'color' => $palette,
                'backgroundColor' => 'transparent',
                'title' => [
                    'text' => $title,
                    'left' => 'left',
                    'textStyle' => ['fontSize' => 14, 'color' => $palette[0]],
                ],
                'tooltip' => [
                    'trigger' => 'item',
                    'formatter' => '{b}<br/>数量: {c} ({d}%)',
                ],
                'legend' => [
                    'orient' => 'horizontal',
                    'bottom' => 0,
                    'textStyle' => ['color' => '#666', 'fontSize' => 12],
                ],
                'series' => [[
                    'name' => $title,
                    'type' => 'pie',
                    'radius' => ['35%', '60%'],
                    'center' => ['50%', '45%'],
                    'avoidLabelOverlap' => true,
                    'itemStyle' => [
                        'borderRadius' => 6,
                        'borderColor' => '#fff',
                        'borderWidth' => 2,
                    ],
                    // ✅ 关键：直接从 response 根级取 pie_data
                    'data' => '${pie_data}',
                    'label' => [
                        'show' => true,
                        'formatter' => '{b} {c} ({d}%)',
                        'fontSize' => 11,
                        // 让文字颜色继承扇区颜色；如果想固定灰色就保留 '#666'
                        'color' => 'inherit',
                    ],
                    'avoidLabelOverlap' => true,
                    'showLabel' => true,
                    'legend' => true,
                    'borderRadius' => 8,
                    'shadow' => [
                        'enabled' => true,
                        'color' => 'rgba(0,0,0,0.4)',
                        'blur' => 8,
                        'offsetX' => 2,
                        'offsetY' => 4,
                    ],
                    'padAngle' => 3, // 扇形间隔
                    'borderWidth' => 3,
                    'borderColor' => '#0002',
                    'labelShadow' => [
                        'enabled' => true,
                        'color' => 'rgba(0,0,0,0.3)',
                        'blur' => 6,
                        'offsetX' => 2,
                        'offsetY' => 2,
                    ],
                    'labelLine' => [
                        'show' => true,
                        'smooth' => true,
                        'length' => 15,
                        'length2' => 10,
                        'lineStyle' => [
                            'width' => 1,
                            'shadow' => [
                                'enabled' => true,
                                'color' => 'rgba(0,0,0,0.5)',
                                'blur' => 3,
                                'offsetX' => 1,
                                'offsetY' => 1,
                            ],
                        ],
                    ],
                    'itemStyle' => ['borderRadius' => 10, 'borderColor' => '#0003', 'borderWidth' => 2],
                ]],
            ]);
    }

    /**
     * 每日执行趋势 - 柱状图
     */
    protected function barChart(string $title = null)
    {
        $palette = app('theme')->echartsPalette();
        return amis()->Chart()
            ->height(280)
            ->config([
                'color' => $palette,
                'backgroundColor' => 'transparent',
                'title' => [
                    'text' => $title, // 可传“最近7日执行趋势”
                    'textStyle' => ['fontSize' => 14, 'color' => $palette[0]],
                    'subtext' => '近7天执行情况分析', // 副标题，按需改，比如统计周期/月份趋势
                    'subtextStyle' => [
                        'color' => '#999',
                        'fontSize' => 12,
                        'padding' => [4, 0, 0, 0],
                    ],
                ],
                'tooltip' => ['trigger' => 'axis'],
                'legend' => [
                    'data' => ['成功', '失败'],
                    'textStyle' => ['color' => '#666', 'fontSize' => 11],
                    'top' => 25,
                ],
                'grid' => ['left' => 50, 'right' => 20, 'top' => 60, 'bottom' => 30],
                'xAxis' => [
                    'type' => 'category',
                    'boundaryGap' => true,
                    // ✅ 根级字段 daily_dates，如 ["09-29","09-30","10-01",...]
                    'data' => '${daily_dates}',
                    'axisLabel' => ['fontSize' => 10],
                ],
                'yAxis' => [
                    'type' => 'value',
                    'min' => 0,
                    'minInterval' => 1,
                    'splitLine' => [
                        'lineStyle' => ['color' => '#0002', 'type' => 'dashed', 'width' => 0.5],
                    ],
                ],
                'series' => [
                    [
                        'name' => '成功',
                        'type' => 'bar',
                        'smooth' => true,
                        // ✅ 根级字段 daily_success
                        'data' => '${daily_success}',
                        'lineStyle' => ['width' => 2],
                        'symbol' => 'circle',
                        'symbolSize' => 6,
                        'itemStyle'  => [
                            'borderRadius' => [4, 4, 0, 0],
                            'color' => [
                                'type' => 'linear',
                                'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                                'colorStops' => [
                                    ['offset' => 0, 'color' => $palette[0]],
                                    ['offset' => 1, 'color' => app('theme')->darkColor($palette[0], 0.3)],
                                ],
                            ],
                            'shadowColor'   => '#0005',
                            'shadowBlur'    => 4,
                            'shadowOffsetX' => 2,
                            'shadowOffsetY' => 2,
                        ],
                    ],
                    [
                        'name' => '失败',
                        'type' => 'bar',
                        'smooth' => true,
                        // ✅ 根级字段 daily_failed
                        'data' => '${daily_failed}',
                        'lineStyle' => ['width' => 2],
                        'symbol' => 'circle',
                        'symbolSize' => 6,
                        'itemStyle'  => [
                            'borderRadius' => [4, 4, 0, 0],
                            'color' => [
                                'type' => 'linear',
                                'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                                'colorStops' => [
                                    ['offset' => 0, 'color' => $palette[1]],
                                    ['offset' => 1, 'color' => app('theme')->darkColor($palette[1], 0.3)],
                                ],
                            ],
                            'shadowColor'   => '#0005',
                            'shadowBlur'    => 4,
                            'shadowOffsetX' => 2,
                            'shadowOffsetY' => 2,
                        ],
                    ],
                ],
            ]);
    }

    /**
     * 每月运行趋势 - 折线图
     */
    protected function lineChart(string $title = null)
    {
        $palette = app('theme')->echartsPalette();
        return amis()->Chart()
            ->height(250)
            ->config([
                'color' => $palette,
                'backgroundColor' => 'transparent',
                'title' => [
                    'text' => $title,
                    'textStyle' => ['fontSize' => 14, 'color' => $palette[0]],
                    'subtext' => '近7天执行情况分析', // 副标题，按需改，比如统计周期/月份趋势
                    'subtextStyle' => [
                        'color' => '#999',
                        'fontSize' => 12,
                        'padding' => [4, 0, 0, 0],
                    ],
                ],
                'tooltip' => ['trigger' => 'axis'],
                'legend' => [
                    'data' => ['成功', '失败'],
                    'textStyle' => ['color' => '#666', 'fontSize' => 11],
                    'top' => 25,
                ],
                'grid' => ['left' => 50, 'right' => 20, 'top' => 60, 'bottom' => 35],
                'xAxis' => [
                    'type' => 'category',
                    'boundaryGap' => false,
                    // ✅ 从根级取 trend_months
                    'data' => '${trend_months}',
                    'axisLabel' => ['fontSize' => 10, 'rotate' => 30],
                ],
                'yAxis' => [
                    'type' => 'value',
                    'min' => 0,
                    'minInterval' => 1,
                    'splitLine' => [
                        'lineStyle' => ['color' => '#0002', 'type' => 'dashed', 'width' => 0.5],
                    ],
                ],
                'series' => [
                    [
                        'name' => '成功',
                        'type' => 'line',
                        'smooth' => true,
                        'data' => '${trend_success}',
                        'symbol' => 'circle',
                        'symbolSize' => 4,
                        // ✅ 显式声明 lineStyle
                        'lineStyle' => [
                            'width' => 2,
                            'color' => $palette[0],
                            'opacity' => 1,
                            'type' => 'solid',
                            'shadowColor' => '#0005',
                            'shadowBlur'  => 4,
                            'shadowOffsetX' => 0,
                            'shadowOffsetY' => 4,
                        ],
                        // ✅ null 值也连线（防止断线）
                        'connectNulls' => true,
                        'itemStyle' => [
                            'color' => '#fff',
                            'borderColor' => $palette[0],
                            'borderWidth' => 1,
                        ],
                        'areaStyle' => [
                            'color' => [
                                'type' => 'linear',
                                'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                                'colorStops' => [
                                    ['offset' => 0, 'color' => $palette[0]],
                                    ['offset' => 1, 'color' => 'transparent'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => '失败',
                        'type' => 'line',
                        'smooth' => true,
                        'data' => '${trend_failed}',
                        'symbol' => 'circle',
                        'symbolSize' => 4,
                        'lineStyle' => [
                            'width' => 2,
                            'color' => $palette[1],
                            'opacity' => 1,
                            'type' => 'solid',
                            'shadowColor' => '#0005',
                            'shadowBlur'  => 4,
                            'shadowOffsetX' => 0,
                            'shadowOffsetY' => 4,
                        ],
                        'connectNulls' => true,
                        'itemStyle' => [
                            'color' => '#fff',
                            'borderColor' => $palette[1],
                            'borderWidth' => 1,
                        ],
                        'areaStyle' => [
                            'color' => [
                                'type' => 'linear',
                                'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                                'colorStops' => [
                                    ['offset' => 0, 'color' => $palette[1]],
                                    ['offset' => 1, 'color' => 'transparent'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }


}
