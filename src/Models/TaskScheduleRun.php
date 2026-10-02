<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 任务执行记录。
 *
 * 与 task_schedule_log 的关系：log 表偏「业务结果」，本表偏「调度器视角」。
 * 包含 exit_code、duration、mutex、overlap 等调度器原生数据，
 * 用于统计分析、健康度监控和 missed-run 检测。
 *
 * 监听器只写本表，业务自定义日志仍写 log 表，两者不冲突。
 */
class TaskScheduleRun extends Model
{
    public $table = 'task_schedule_run';

    protected $fillable = [
        'task_id', 'event_name', 'command',
        'expression', 'timezone',
        'state',           // running / success / failed / skipped
        'exit_code', 'output', 'error_message',
        'started_at', 'finished_at', 'duration',
        'mutex_name', 'skipped_because_overlapping',
    ];

    protected $casts = [
        'task_id'       => 'integer',
        'exit_code'     => 'integer',
        'duration'      => 'float',
        'skipped_because_overlapping' => 'boolean',
    ];

    protected $dates = [
        'started_at', 'finished_at',
    ];
}
