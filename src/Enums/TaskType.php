<?php

namespace DagaSmart\TaskSchedule\Enums;

/**
 * 任务类型枚举
 */
enum TaskType: string
{
    case COMMAND = 'command';
    case JOB = 'job';
    case URL = 'url';
    case SHELL = 'shell';
    case CLOSURE = 'closure';

    public function description(): string
    {
        return match ($this) {
            self::COMMAND => 'Artisan 命令',
            self::JOB => '队列任务',
            self::URL => 'HTTP 请求',
            self::SHELL => 'Shell 脚本',
            self::CLOSURE => '闭包执行',
        };
    }

    public static function fromValue(string $value): self
    {
        return match ($value) {
            'command' => self::COMMAND,
            'job' => self::JOB,
            'url' => self::URL,
            'shell' => self::SHELL,
            'closure' => self::CLOSURE,
            default => self::COMMAND,
        };
    }
}
