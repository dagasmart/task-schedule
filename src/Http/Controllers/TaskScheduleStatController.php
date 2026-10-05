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
 *   - 移除原代码中硬编码的随机数据与无关联示例代码块；
 *   - 成功率 null 时显示 '-'，避免误导用户。
 */
class TaskScheduleStatController extends AdminController
{
    protected string $serviceName = TaskScheduleStatService::class;

    public function index()
    {
        $summary = $this->service->summary();
        $daily   = $this->service->dailyTrend();
        $month   = $this->service->monthTrend();
        $dist    = $this->service->groupDistribution();

        // 趋势数据兜底，确保图表不因 Service 返回异常 key 而空白
        $empty = ['dates' => [], 'success' => [], 'failed' => []];
        $daily = array_merge($empty, $daily);
        $month = array_merge($empty, $month);

        // 成功率展示处理：无完结记录时显示 '-'
        $successRate = $summary['success_rate'] ?? null;
        $summary['success_rate'] = $successRate !== null
            ? $successRate
            : '-';

        $page = $this->basePage()->css($this->css())->interval(2000)->body([
            amis()->Grid()->columns([
                $this->statCards($summary)->set('md', 12),
            ]),
            amis()->Grid()->columns([
                $this->pieChart('执行结果分布', $dist)->set('md', 4),
                $this->barChart('每日执行趋势', $daily)->set('md', 8),
            ]),
            amis()->Grid()->columns([
                $this->lineChart('每月运行趋势', $month)->set('md', 8),
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
            // 浮点值统一格式化，避免过多小数位
            $value = is_numeric($card['value'])
                ? (is_float($card['value']) ? number_format((float) $card['value'], 2) : $card['value'])
                : $card['value'];

            return amis()->Panel()->body([
                amis()->Flex()->direction('column')->items([
                    amis()->Tpl()->tpl(
                        "<div class='text-sm text-gray-500'>" . e($card['label']) . "</div>"
                    ),
                    amis()->Tpl()->tpl(
                        "<div class='text-2xl font-bold'>{$value}<span class='text-sm ml-1'>" . e($card['unit']) . "</span></div>"
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
                'color' => app('theme')->echartsPalette(),
                'backgroundColor' => 'transparent',
                'title'   => ['text' => $title, 'textStyle' => ['color' => themeColor()],],
                'tooltip' => ['trigger' => 'item'],
                'legend'  => [
                    'bottom' => 0,
                    'left' => 'center',
                    'textStyle' => [
                        'color' => '#999',
                    ],
                ],
                'series'  => [[
                    'name'              => $title,
                    'type'              => 'pie',
                    'radius'            => ['40%', '70%'],
                    'avoidLabelOverlap' => true,
                    'showLabel'         => true,
                    'itemStyle'         => ['borderRadius' => 10, 'borderColor' => '#0003', 'borderWidth' => 2],
                    'label' => [
                        'show' => true,
                        'formatter' => '{b} {c} ({d}%)',
                        'fontSize' => 11,
                        'color' => '#666',
                    ],
                    // 兜底数据（"暂无数据"）不放大高亮，避免误导
                    'emphasis'          => ['label' => ['show' => false]],
                    'data'              => $data === [] ? [['name' => '暂无数据', 'value' => 1]] : $data,
                    'padAngle' => 4, // 扇形间隔
                    'borderWidth' => 2,
                    'borderColor' => '#0002',
                    'labelShadow' => [
                        'enabled' => true,
                        'color' => 'rgba(0,0,0,0.3)',
                        'blur' => 2,
                        'offsetX' => 1,
                        'offsetY' => 1,
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
                ]],
            ]),
        ]);
    }

    /**
     * 柱状图：每日执行趋势。
     */
    private function barChart(string $title, array $daily)
    {
        $palette = app('theme')->echartsPalette();
        return amis()->Panel()->className('w-full h-96')->body([
            amis()->Chart()->height(320)->config([
                'color' => $palette,
                'backgroundColor' => 'transparent',
                'title'   => [
                    'text' => $title,
                    'textStyle' => ['color' => $palette[0]],
                    'subtext' => '近7天执行情况分析', // 副标题，按需改，比如统计周期/月份趋势
                    'subtextStyle' => [
                        'color' => '#999',
                        'fontSize' => 12,
                        'padding' => [4, 0, 0, 0],
                    ],
                ],
                'tooltip' => ['trigger' => 'axis'],
                'legend'  => [
                    'textStyle' => [
                        'color' => '#999',
                    ],
                    'data' => ['成功', '失败'],
                ],
                'grid'    => ['left' => 65, 'right' => 25, 'top' => 60, 'bottom' => 30],
                'xAxis'   => [
                    'type'        => 'category',
                    'boundaryGap' => true,
                    'data'        => $daily['dates'],
                ],
                'yAxis' => [
                    'type' => 'value',
                    'splitLine' => [
                        'lineStyle' => [
                            'color' => '#0005',   // 主题色 12% 透明度
                            'type'  => 'dashed',
                            'width' => 0.5,
                        ],
                    ],
                ],
                'series' => [
                    [
                        'name'      => '成功',
                        'type'      => 'bar',
                        'data'      => $daily['success'],
                        'barGap'     => '30%',
                        'itemStyle'  => [
                            'borderRadius' => [8, 8, 0, 0],
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
                        'name'      => '失败',
                        'type'      => 'bar',
                        'data'      => $daily['failed'],
                        'barGap'     => '30%',
                        'itemStyle'  => [
                            'borderRadius' => [8, 8, 0, 0],
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
            ]),
        ]);
    }

    /**
     * 折线图：近 N 天运行趋势。
     */
    private function lineChart(string $title, array $month)
    {
        $palette = app('theme')->echartsPalette();
        return amis()->Panel()->className('clear-card-mb h-96')->body([
            amis()->Chart()->height(320)->config([
                'color' => $palette,
                'backgroundColor' => 'transparent',
                'title'   => [
                    'text' => $title,
                    'textStyle' => ['color' => $palette[0]],
                    'subtext' => '近12个月运行数据分析', // 副标题，按需改，比如统计周期/月份趋势
                    'subtextStyle' => [
                        'color' => '#999',
                        'fontSize' => 12,
                        'padding' => [4, 0, 0, 0],
                    ],
                ],
                'tooltip' => ['trigger' => 'axis'],
                'legend' => [
                    'textStyle' => [
                        'color' => '#999',
                    ],
                    'data' => [
                        [
                            'name' => '成功',
                            'icon' => 'circle',
                            'itemStyle' => [
                                'color' => 'transparent',
                                'borderColor' => 'auto',
                                'borderWidth' => 1,
                            ],

                        ],
                        [
                            'name' => '失败',
                            'icon' => 'circle',
                            'itemStyle' => [
                                'color' => 'transparent',
                                'borderColor' => 'auto',
                                'borderWidth' => 1,
                            ],
                        ],
                    ],
                    'itemWidth' => 10,
                    'itemHeight' => 10,
                ],
                'grid'    => ['left' => 80, 'right' => 45, 'top' => 60, 'bottom' => 30],
                'xAxis'   => [
                    'type'        => 'category',
                    'boundaryGap' => false,
                    'data'        => $month['dates'],
                ],
                'yAxis' => [
                    'type' => 'value',
                    'splitLine' => [
                        'lineStyle' => [
                            'color' => '#0005',   // 主题色 12% 透明度
                            'type'  => 'dashed',
                            'width' => 0.5,
                        ],
                    ],
                ],
                'series' => [
                    [
                        'name'      => '成功',
                        'type'      => 'line',
                        'data'      => $month['success'],
                        'smooth'    => true,
                        'lineStyle' => [
                            'width'       => 2,
                            'shadowColor' => '#0005',
                            'shadowBlur'  => 4,
                            'shadowOffsetX' => 0,
                            'shadowOffsetY' => 4,
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
                        'name'      => '失败',
                        'type'      => 'line',
                        'data'      => $month['failed'],
                        'smooth'    => true,
                        'lineStyle' => [
                            'width'       => 2,
                            'shadowColor' => '#0005',
                            'shadowBlur'  => 4,
                            'shadowOffsetX' => 0,
                            'shadowOffsetY' => 4,
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
            ]),
        ]);
    }

    /**
     * 失败 Top 榜。
     *
     * 使用 Table（展示型）组件渲染静态数据。
     */
    private function failurePanel(array $topFailures)
    {
        $rows = $topFailures ?: [['task_name' => '暂无失败记录', 'fail_cnt' => 0]];

        return amis()->Panel()->className('h-full')->body([
            amis()->Tpl()->tpl('<div class="text-lg font-bold mb-3">失败 Top 榜</div>'),
            amis()->Table()->data($rows)->columns([
                ['name' => 'task_name', 'label' => '任务名称'],
                ['name' => 'fail_cnt', 'label' => '失败次数'],
            ]),
        ]);
    }

    public function summary()
    {
        $record = $this->service->summary();
        return $this->response()->success();

    }

    private function css(): array
    {
        return [
            '.clear-card-mb' => ['margin-bottom' => '0 !important'],
            '.cxd-Image'     => ['border' => 'none'],
        ];
    }
}
