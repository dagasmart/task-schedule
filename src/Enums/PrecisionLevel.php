<?php

namespace DagaSmart\TaskSchedule\Enums;

/**
 * 调度精度级别
 * 支持：秒/分/时/天/周/月 六级精度
 */
enum PrecisionLevel: int
{
    case SECOND = 1;   // 秒级
    case MINUTE = 2;   // 分级
    case HOUR = 3;     // 时级
    case DAY = 4;      // 天级
    case WEEK = 5;     // 周级
    case MONTH = 6;    // 月级

    public function label(): string
    {
        return match ($this) {
            self::SECOND => '秒级',
            self::MINUTE => '分级',
            self::HOUR => '时级',
            self::DAY => '天级',
            self::WEEK => '周级',
            self::MONTH => '月级',
        };
    }

    /**
     * 获取该精度下 cron 表达式的最小字段数
     */
    public function minCronFields(): int
    {
        return match ($this) {
            self::SECOND => 6, // 秒 分 时 日 月 周
            self::MINUTE => 5, // 分 时 日 月 周
            self::HOUR => 5,   // 时 日 月 周 (标准5字段)
            self::DAY => 5,
            self::WEEK => 5,
            self::MONTH => 5,
        };
    }

    /**
     * 获取默认 cron 表达式
     */
    public function defaultExpression(): string
    {
        return match ($this) {
            self::SECOND => '* * * * * *',
            self::MINUTE => '* * * * *',
            self::HOUR => '0 * * * *',
            self::DAY => '0 0 * * *',
            self::WEEK => '0 0 * * 1',
            self::MONTH => '0 0 1 * *',
        };
    }
}
