<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * 任务调度模型
 *
 * 支持月/周/天/时/分/秒 六级精度调度
 * 支持 PostgreSQL 分布式锁防重叠
 */
class TaskSchedule extends Model
{
    public $table = 'task_schedule';

    protected $appends = ['group_name', 'precision_level'];

    protected $fillable = [
        'task_name', 'task_type', 'description', 'command', 'parameters',
        'expression', 'precision', 'active', 'timezone',
        'environments', 'without_overlapping', 'overlap_release_minutes',
        'on_one_server', 'in_background', 'in_maintenance_mode',
        'output_file_path', 'output_append', 'output_email', 'output_email_on_failure',
        'max_runtime', 'timeout_action', 'retry_times', 'retry_interval',
        'priority', 'concurrent_limit', 'interval_seconds', 'version',
        'group_id', 'module', 'mer_id', 'creator_id', 'creator',
    ];

    protected $casts = [
        'active' => 'boolean',
        'environments' => 'array',
        'on_one_server' => 'boolean',
        'in_background' => 'boolean',
        'in_maintenance_mode' => 'boolean',
        'output_append' => 'boolean',
        'output_email_on_failure' => 'boolean',
        'without_overlapping' => 'boolean',
        'overlap_release_minutes' => 'integer',
        'max_runtime' => 'integer',
        'retry_times' => 'integer',
        'retry_interval' => 'integer',
        'priority' => 'integer',
        'concurrent_limit' => 'integer',
        'precision' => 'integer',
        'interval_seconds' => 'integer',
        'version' => 'integer',
        'mer_id' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | 查询作用域
    |--------------------------------------------------------------------------
    */

    /**
     * 仅查询激活的任务
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * 按优先级排序
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc')
            ->orderBy('id', 'asc');
    }

    /**
     * 查询需要执行的任务（根据精度级别）
     */
    public function scopeDueForPrecision(Builder $query, int $precision): Builder
    {
        return $query->active()
            ->where('precision', $precision)
            ->where(function ($q) {
                $q->whereNull('last_run_at')
                    ->orWhereRaw('next_run_at <= NOW()');
            });
    }

    /*
    |--------------------------------------------------------------------------
    | 关联关系
    |--------------------------------------------------------------------------
    */

    /**
     * 所属分组
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TaskScheduleGroup::class, 'group_id');
    }

    /**
     * 执行日志
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskScheduleLog::class, 'task_id');
    }

    /**
     * 执行分发记录
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(TaskScheduleDispatch::class, 'task_id');
    }

    /*
    |--------------------------------------------------------------------------
    | 访问器
    |--------------------------------------------------------------------------
    */

    /**
     * 获取分组名称
     */
    public function getGroupNameAttribute(): ?string
    {
        return $this->group?->group_name;
    }

    /**
     * 获取精度级别
     */
    public function getPrecisionLevelAttribute(): string
    {
        return match ($this->precision) {
            1 => 'second',
            2 => 'minute',
            3 => 'hour',
            4 => 'day',
            5 => 'week',
            6 => 'month',
            default => 'minute',
        };
    }

    /**
     * 计算下次执行时间
     */
    public function calculateNextRun(Carbon $from = null): ?Carbon
    {
        $from = $from ?? Carbon::now($this->timezone ?? config('app.timezone'));

        try {
            $cron = \Cron\CronExpression::factory($this->expression);
            return Carbon::parse($cron->getNextRunDate($from));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 业务方法
    |--------------------------------------------------------------------------
    */

    /**
     * 获取所有分组（树形结构）
     */
    public function getGroups()
    {
        return TaskScheduleGroup::query()
            ->select(['id', 'id as value', 'group_name as label', 'parent_id'])
            ->orderBy('sort')
            ->get();
    }

    /**
     * 状态选项
     */
    public function stateOption(): array
    {
        return [
            ['label' => '暂停下线', 'value' => 0],
            ['label' => '正常上线', 'value' => 1],
        ];
    }

    /**
     * 精度级别选项
     */
    public function precisionOptions(): array
    {
        return [
            ['label' => '秒级 (1秒~59秒)', 'value' => 1],
            ['label' => '分级 (1分~59分)', 'value' => 2],
            ['label' => '时级 (1时~23时)', 'value' => 3],
            ['label' => '天级 (每天)', 'value' => 4],
            ['label' => '周级 (每周)', 'value' => 5],
            ['label' => '月级 (每月)', 'value' => 6],
        ];
    }
}
