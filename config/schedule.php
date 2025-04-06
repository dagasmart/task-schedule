<?php

return [
    'table' => 'task_schedule',

    'model' => \DagaSmart\TaskSchedule\Models\TaskSchedule::class,

    'output' => [
        'path' => storage_path('logs'),
    ],
];
