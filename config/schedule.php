<?php

/*
 * This file is part of the dagasmart/task-schedule.
 *
 * (c) jiannei <longjian.huang@foxmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
    'table' => 'basic_schedules',

    'model' => \DagaSmart\TaskSchedule\Models\BasicSchedule::class,

    'output' => [
        'path' => storage_path('logs'),
    ],
];
