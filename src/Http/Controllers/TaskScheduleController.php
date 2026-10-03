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
                            amis()->Drawer()->title('下次执行时间预览')
                                ->body(
                                    amis()->Service()->api(admin_url('task-schedule/${id}/preview'))
                                        ->body([
                                            amis()->Table()->columns([
                                                amis()->TableColumn('run_time', '执行时间')->type('datetime'),
                                            ])
                                        ])
                                )
                        ),
                    // 日志
                    amis()->LinkAction()->label('日志')
                        ->level('link')->className('text-dark')
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
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);

        $runs = $this->service->previewNextRuns($request->id, 10);
        return $this->response()->success($runs);
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
}
