<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Enums;

/**
 * 任务执行状态枚举
 */
enum TaskState: int
{
    case PENDING = 0;      // 等待中
    case RUNNING = 1;      // 运行中
    case SUCCESS = 2;      // 成功
    case FAILED = 3;       // 失败
    case TIMEOUT = 4;      // 超时
    case CANCELLED = 5;    // 已取消
    case RETRYING = 6;     // 重试中
    case SKIPPED = 7;      // 跳过（防重叠）

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '等待中',
            self::RUNNING => '运行中',
            self::SUCCESS => '成功',
            self::FAILED => '失败',
            self::TIMEOUT => '超时',
            self::CANCELLED => '已取消',
            self::RETRYING => '重试中',
            self::SKIPPED => '跳过',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RUNNING => 'info',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
            self::TIMEOUT => 'danger',
            self::CANCELLED => 'default',
            self::RETRYING => 'warning',
            self::SKIPPED => 'default',
        };
    }
}
