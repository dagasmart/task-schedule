<?php

namespace DagaSmart\TaskSchedule\Http\Controllers;

use DagaSmart\BizAdmin\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use DagaSmart\BizAdmin\Controllers\AdminController;
use DagaSmart\TaskSchedule\Services\TaskScheduleStatService;

class TaskScheduleStatController extends AdminController
{
    protected string $serviceName = TaskScheduleStatService::class;

    public function index(): JsonResponse|JsonResource
    {
        $page = $this->basePage()->css($this->css())->body([
            amis()->Grid()->className('mb-1')->columns([
                $this->pieChart()->set('md', 4),
                $this->barChart()->set('md', 8),
//                amis()->Flex()->items([
//                    $this->pieChart(),
//                    $this->pieChart(),
//                ]),
            ]),
            amis()->Grid()->className('mb-1')->columns([
                $this->barChart()->set('md', 6),
                $this->pieChart()->set('md', 3),
                $this->pieChart()->set('md', 3),
//                amis()->Flex()->items([
//                    $this->pieChart(),
//                    $this->pieChart(),
//                ]),
            ]),
            amis()->Grid()->columns([
                $this->lineChart()->set('md', 8),
                amis()->Flex()->className('h-full')->items([
                    $this->clock(),
                    $this->codeView(),
                ])->direction('column'),
            ]),
        ]);

        return $this->response()->success($page);
    }

    public function codeView()
    {
        return amis()->Panel()->className('h-full clear-card-mb rounded-md')->body([
            amis()->Markdown()->options(['html' => true, 'breaks' => true])->value(
                <<<MD
### __The beginning of everything__

<br>

```php
<?php

echo 'Hello World';
```
MD
            ),
        ])->id('code-view-panel')->set('animations', [
            'enter' => [
                'delay'    => 0.65,
                'duration' => 0.5,
                'type'     => 'fadeInRight',
            ],
        ]);
    }

    public function clock()
    {
        /** @noinspection all */
        $panel = amis()->Panel()->className('h-full bg-blingbling')->body([
            amis()->Tpl()->tpl('<div class="text-2xl font-bold mb-4">Clock</div>'),
            amis()->Custom()
                ->name('clock')
                ->html('<div id="clock" class="text-4xl"></div><div id="clock-date" class="mt-5"></div>')
                ->onMount(
                    <<<JS
const clock = document.getElementById('clock');
const tick = () => {
    clock.innerHTML = (new Date()).toLocaleTimeString();
    requestAnimationFrame(tick);
};
tick();

const clockDate = document.getElementById('clock-date');
clockDate.innerHTML = (new Date()).toLocaleDateString();
JS

                ),
        ]);

        return amis()->Wrapper()->size('none')->className('h-full mb-3')->id('clock-panel')->set('animations', [
            'enter' => [
                'delay'    => 0.5,
                'duration' => 0.5,
                'type'     => 'fadeInRight',
            ],
        ])->body($panel);
    }

    public function frameworkInfo()
    {
        $link = function ($label, $link) {
            return amis()->Action()
                ->level('link')
                ->className('text-lg font-semibold')
                ->label($label)
                ->set('blank', true)
                ->actionType('url')
                ->link($link);
        };

        return amis()->Panel()->className('h-96')->body(
            amis()->Wrapper()->className('h-full')->body([
                amis()->Flex()
                    ->className('h-full')
                    ->direction('column')
                    ->justify('center')
                    ->alignItems('center')
                    ->items([
                        amis()->Image()->src(url(Admin::config('admin.logo'))),
                        amis()->Wrapper()->className('text-3xl mt-9 font-bold')->body(Admin::config('admin.name')),
                        amis()->Flex()->className('w-full mt-5')->justify('center')->items([
                            $link('代码', 'https://github.com/dagasmart/bizadmin'),
                            $link('官网', 'https://biz.dagasmart.com'),
                            $link('文档', 'https://doc.biz.dagasmart.com'),
                            $link('演示', 'https://demo.biz.dagasmart.com'),
                        ]),
                    ]),
            ])
        )->id('framework-info')->set('animations', [
            'enter' => [
                'delay'    => 0,
                'duration' => 0.5,
                'type'     => 'zoomIn',
            ],
        ]);
    }

    public function pieChart()
    {
        return amis()->Panel()->className('w-full h-96')->body([
            amis()->Chart()->height(350)->config([
                'backgroundColor' => '',
                'tooltip'         => ['trigger' => 'item'],
                'legend'          => ['bottom' => 0, 'left' => 'center'],
                'series'          => [
                    [
                        'name'              => 'Access From',
                        'type'              => 'pie',
                        'radius'            => ['40%', '70%'],
                        'avoidLabelOverlap' => false,
                        'itemStyle'         => ['borderRadius' => 10, 'borderColor' => '#fff', 'borderWidth' => 2],
                        'label'             => ['show' => false, 'position' => 'center'],
                        'emphasis'          => [
                            'label' => [
                                'show'       => true,
                                'fontSize'   => '40',
                                'fontWeight' => 'bold',
                            ],
                        ],
                        'labelLine'         => ['show' => false],
                        'data'              => [
                            ['value' => 1048, 'name' => 'Search Engine'],
                            ['value' => 735, 'name' => 'Direct'],
                            ['value' => 580, 'name' => 'Email'],
                            ['value' => 484, 'name' => 'Union Ads'],
                            ['value' => 300, 'name' => 'Video Ads'],
                        ],
                    ],
                ],
            ])
        ])->id('pie-chart-panel')->set('animations', [
            'enter' => [
                'delay'    => 0.1,
                'duration' => 0.5,
                'type'     => 'zoomIn',
            ],
        ]);
    }

    public function barChart()
    {
        return amis()->Panel()->className('w-full h-96')->body([
            amis()->Chart()->height(350)->config([
                'backgroundColor' => '',
                'title'           => [
                    'text' => '任务汇总统计',
                    'subtext' => '统计图'
                ],
                'tooltip'         => ['trigger' => 'axis'],
                'legend'          => ['data' => ['最高气温', '最低气温']],
                'xAxis'           => [
                    'type'        => 'category',
                    'boundaryGap' => false,
                    'data'        => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                ],
                'yAxis'           => ['type' => 'value'],
                'grid'            => ['left' => '7%', 'right' => '3%', 'top' => 60, 'bottom' => 30,],
                'legend'          => ['data' => ['成功', '失败']],
                'series'          => [
                    [
                        'name'      => '成功',
                        'data'      => [10,2,30,4,50,16,7],
                        'type'      => 'line',
                        'areaStyle' => [],
                        'smooth'    => true,
                        'symbol'    => 'none',
                    ],
                    [
                        'name'      => '失败',
                        'data'      => [7,6,5,4,3,2,1],
                        'type'      => 'bar',
                        'areaStyle' => [],
                        'smooth'    => true,
                        'symbol'    => 'none',
                    ],
                ],
            ])
        ])->id('pie-chart-panel')->set('animations', [
            'enter' => [
                'delay'    => 0.1,
                'duration' => 0.5,
                'type'     => 'zoomIn',
            ],
        ]);
    }

    public function lineChart()
    {
        $randArr = function () {
            $_arr = [];
            for ($i = 0; $i < 7; $i++) {
                $_arr[] = rand(50, 200);
            }
            return $_arr;
        };

        $random1 = $randArr();
        $random2 = $randArr();

        $chart = amis()->Chart()->height(380)->className('h-96')->config([
            'backgroundColor' => '',
            'title'           => ['text' => 'Users Behavior'],
            'tooltip'         => ['trigger' => 'axis'],
            'xAxis'           => [
                'type'        => 'category',
                'boundaryGap' => false,
                'data'        => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            ],
            'yAxis'           => ['type' => 'value'],
            'grid'            => ['left' => '7%', 'right' => '3%', 'top' => 60, 'bottom' => 30,],
            'legend'          => ['data' => ['Visits', 'Bounce Rate']],
            'series'          => [
                [
                    'name'      => 'Visits',
                    'data'      => $random1,
                    'type'      => 'line',
                    'areaStyle' => [],
                    'smooth'    => true,
                    'symbol'    => 'none',
                ],
                [
                    'name'      => 'Bounce Rate',
                    'data'      => $random2,
                    'type'      => 'line',
                    'areaStyle' => [],
                    'smooth'    => true,
                    'symbol'    => 'none',
                ],
            ],
        ]);

        return amis()->Panel()->className('clear-card-mb')->body($chart)->id('line-chart-panel')->set('animations', [
            'enter' => [
                'delay'    => 0.3,
                'duration' => 0.5,
                'type'     => 'zoomIn',
            ],
        ]);
    }

    private function css(): array
    {
        /** @noinspection all */
        return [
            '.clear-card-mb'                 => [
                'margin-bottom' => '0 !important',
            ],
            '.cxd-Image'                     => [
                'border' => '0',
            ],
            '.bg-blingbling'                 => [
                'color'             => '#fff',
                'background'        => 'linear-gradient(to bottom right, #2C3E50, #FD746C, #FF8235, #ffff1c, #92FE9D, #00C9FF, #a044ff, #e73827)',
                'background-repeat' => 'no-repeat',
                'background-size'   => '1000% 1000%',
                'animation'         => 'gradient 60s ease infinite',
            ],
            '@keyframes gradient'            => [
                '0%{background-position:0% 0%} 50%{background-position:100% 100%} 100%{background-position:0% 0%}',
            ],
            '.bg-blingbling .cxd-Card-title' => [
                'color' => '#fff',
            ],
        ];
    }
}
