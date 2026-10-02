<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\TaskSchedule\Services\TaskScheduleStatService;

/**
 * 任务调度统计控制器。
 *
 * 升级说明：
 *   - 饼图/柱状图/折线图全部接入真实统计数据；
 *   - 保留原有的动画与样式结构，避免影响前端视觉一致性；
 *   - 移除原代码中硬编码的随机数据与无关联示例代码块。
 */
class TaskScheduleStatController extends AdminController
{
    protected string $serviceName = TaskScheduleStatService::class;

    public function index()
    {
        $summary = $this->service->summary();
        $trend   = $this->service->dailyTrend();
        $dist    = $this->service->groupDistribution();

        $page = $this->basePage()->css($this->css())->body([
            amis()->Grid()->columns([
                $this->statCards($summary)->set('md', 12),
            ]),
            amis()->Grid()->columns([
                $this->pieChart('执行结果分布', $dist)->set('md', 4),
                $this->barChart('每日执行趋势', $trend)->set('md', 8),
            ]),
            amis()->Grid()->columns([
                $this->lineChart('近 14 天运行趋势', $trend)->set('md', 8),
                $this->failurePanel($summary['top_failures'] ?? [])->set('md', 4),
            ]),
        ]);

        return $this->response()->success($page);
    }

    /**
     * 顶部统计卡片。
     */
    private function statCards(array $summary)
    {
        $cards = [
            ['label' => '任务总数', 'value' => $summary['task_total'], 'unit' => '个'],
            ['label' => '启用任务', 'value' => $summary['task_active'], 'unit' => '个'],
            ['label' => '执行次数', 'value' => $summary['run_total'], 'unit' => '次'],
            ['label' => '成功率', 'value' => $summary['success_rate'], 'unit' => '%'],
            ['label' => '平均耗时', 'value' => $summary['avg_duration'], 'unit' => '秒'],
        ];

        $items = array_map(function ($card) {
            return amis()->Panel()->body([
                amis()->Flex()->direction('column')->items([
                    amis()->Tpl()->tpl(
                        "<div class='text-sm text-gray-500'>{$card['label']}</div>"
                    ),
                    amis()->Tpl()->tpl(
                        "<div class='text-2xl font-bold'>{$card['value']}<span class='text-sm ml-1'>{$card['unit']}</span></div>"
                    ),
                ]),
            ])->className('h-full');
        }, $cards);

        return amis()->Panel()->className('w-full')->body([
            amis()->Grid()->columns($items),
        ]);
    }

    /**
     * 饼图：执行结果分布。
     */
    private function pieChart(string $title, array $data)
    {
        return amis()->Panel()->className('w-full h-96')->body([
            amis()->Chart()->height(320)->config([
                'backgroundColor' => '',
                'title'   => ['text' => $title],
                'tooltip' => ['trigger' => 'item'],
                'legend'  => ['bottom' => 0, 'left' => 'center'],
                'series'  => [[
                    'name'              => $title,
                    'type'              => 'pie',
                    'radius'            => ['40%', '70%'],
                    'avoidLabelOverlap' => false,
                    'itemStyle'         => ['borderRadius' => 10, 'borderColor' => '#fff', 'borderWidth' => 2],
                    'label'             => ['show' => false, 'position' => 'center'],
                    'emphasis'          => ['label' => ['show' => true, 'fontSize' => '20', 'fontWeight' => 'bold']],
                    'labelLine'         => ['show' => false],
                    'data'              => $data === [] ? [['name' => '暂无数据', 'value' => 1]] : $data,
                ]],
            ]),
        ]);
    }

    /**
     * 柱状图：每日执行趋势。
     */
    private function barChart(string $title, array $trend)
    {
        return amis()->Panel()->className('w-full h-96')->body([
            amis()->Chart()->height(320)->config([
                'backgroundColor' => '',
                'title'   => ['text' => $title],
                'tooltip' => ['trigger' => 'axis'],
                'legend'  => ['data' => ['成功', '失败']],
                'grid'    => ['left' => '3%', 'right' => '3%', 'top' => 60, 'bottom' => 30],
                'xAxis'   => [
                    'type'        => 'category',
                    'boundaryGap' => true,
                    'data'        => $trend['dates'] ?? [],
                ],
                'yAxis' => ['type' => 'value'],
                'series' => [
                    [
                        'name' => '成功', 'type' => 'bar',
                        'data' => $trend['success'] ?? [],
                        'itemStyle' => ['borderRadius' => [4, 4, 0, 0]],
                    ],
                    [
                        'name' => '失败', 'type' => 'bar',
                        'data' => $trend['failed'] ?? [],
                        'itemStyle' => ['borderRadius' => [4, 4, 0, 0]],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * 折线图：近 N 天运行趋势。
     */
    private function lineChart(string $title, array $trend)
    {
        return amis()->Panel()->className('clear-card-mb h-96')->body([
            amis()->Chart()->height(320)->config([
                'backgroundColor' => '',
                'title'   => ['text' => $title],
                'tooltip' => ['trigger' => 'axis'],
                'legend'  => ['data' => ['成功', '失败']],
                'grid'    => ['left' => '3%', 'right' => '3%', 'top' => 60, 'bottom' => 30],
                'xAxis'   => [
                    'type'        => 'category',
                    'boundaryGap' => false,
                    'data'        => $trend['dates'] ?? [],
                ],
                'yAxis' => ['type' => 'value'],
                'series' => [
                    [
                        'name'      => '成功', 'type' => 'line',
                        'data'      => $trend['success'] ?? [],
                        'areaStyle' => [], 'smooth' => true, 'symbol' => 'none',
                    ],
                    [
                        'name'      => '失败', 'type' => 'line',
                        'data'      => $trend['failed'] ?? [],
                        'areaStyle' => [], 'smooth' => true, 'symbol' => 'none',
                    ],
                ],
            ]),
        ]);
    }

    /**
     * 失败 Top 榜。
     */
    private function failurePanel(array $topFailures)
    {
        $rows = empty($topFailures)
            ? [['task_name' => '暂无失败记录', 'fail_cnt' => 0]]
            : $topFailures;

        return amis()->Panel()->className('h-full')->body([
            amis()->Tpl()->tpl('<div class="text-lg font-bold mb-3">失败 Top 榜</div>'),
            amis()->TableControl()->items($rows)->columns([
                ['name' => 'task_name', 'label' => '任务名称'],
                ['name' => 'fail_cnt', 'label' => '失败次数'],
            ]),
        ]);
    }

    private function css(): array
    {
        return [
            '.clear-card-mb'          => ['margin-bottom' => '0 !important'],
            '.cxd-Image'              => ['border' => '0'],
        ];
    }
}
