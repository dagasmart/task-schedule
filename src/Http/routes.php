<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use DagaSmart\TaskSchedule\Http\Controllers;

Route::get('task-schedule', [Controllers\TaskScheduleController::class, 'index']);

// 任务调度 CRUD
Route::resource('task-schedule/index', Controllers\TaskScheduleController::class);
Route::post('task-schedule/{id}/execute', [Controllers\TaskScheduleController::class, 'execute']);
Route::post('task-schedule/{id}/preview', [Controllers\TaskScheduleController::class, 'preview']);
Route::post('task-schedule/{id}/pause', [Controllers\TaskScheduleController::class, 'pause']);
Route::post('task-schedule/{id}/resume', [Controllers\TaskScheduleController::class, 'resume']);
Route::post('task-schedule/batch', [Controllers\TaskScheduleController::class, 'batchAction']);

// 任务分组 CRUD
Route::get('task-schedule/group/treeOption', [Controllers\TaskScheduleGroupController::class, 'treeOption']);
Route::resource('task-schedule/group', Controllers\TaskScheduleGroupController::class);

// 任务日志
Route::resource('task-schedule/log', Controllers\TaskScheduleLogController::class);

// 统计分析
Route::get('task-schedule/stat/dashboard', [Controllers\TaskScheduleStatController::class, 'dashboard']);
Route::get('task-schedule/stat/summary', [Controllers\TaskScheduleStatController::class, 'summary']);
Route::get('task-schedule/stat/state-distribution', [Controllers\TaskScheduleStatController::class, 'stateDistribution']);
Route::get('task-schedule/stat/hourly-trend', [Controllers\TaskScheduleStatController::class, 'hourlyTrend']);
Route::get('task-schedule/stat', [Controllers\TaskScheduleStatController::class, 'index']);
