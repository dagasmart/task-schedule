<?php

use DagaSmart\TaskSchedule\Http\Controllers;
use Illuminate\Support\Facades\Route;

Route::get('task-schedule', [Controllers\TaskScheduleController::class, 'index']);

Route::resource('task-schedule/index', Controllers\TaskScheduleController::class);
Route::resource('task-schedule/group', Controllers\TaskScheduleGroupController::class);
Route::resource('task-schedule/stat', Controllers\TaskScheduleStatController::class);
