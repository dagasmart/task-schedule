<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskScheduleLog extends Model
{
    public $table = 'task_schedule_log';

    protected $fillable = [
        'task_id',
        'task_name',
        'command',
        'description',
        'state',         // boolean: true=成功, false=失败
        'result',        // jsonb: 业务结果
        'output',        // jsonb: 输出信息
        'exit_code',
        'duration',
        'memory_peak',
        'pid',
        'worker_id',
        'module',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'output'       => 'array', // jsonb → array
        'result'       => 'array', // jsonb → array
        'duration'     => 'float',
        'exit_code'    => 'integer',
        'memory_peak'  => 'integer',
        'pid'          => 'integer',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'state'        => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskSchedule::class, 'task_id');
    }

    /**
     * 查询成功的任务
     */
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('state', true);
    }

    /**
     * 查询失败的任务
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('state', false);
    }

    /**
     * 按时间范围查询
     */
    public function scopeInRange(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
