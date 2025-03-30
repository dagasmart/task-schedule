<?php

return [
    'table' => 'basic_schedules',

    'model' => \DagaSmart\TaskSchedule\Models\BasicSchedule::class,

    'output' => [
        'path' => storage_path('logs'),
    ],
];
