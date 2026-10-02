<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Engine;

use DagaSmart\TaskSchedule\Enums\PrecisionLevel;

/**
 * 时间轮内的任务条目（值对象）。
 *
 * 与 TaskSchedule 模型的关系：
 *   - 模型是持久化层（PostgreSQL 里的 task_schedule 表）
 *   - TaskEntry 是运行层，由模型 hydrate 而来，带时间轮所需的最小字段
 *
 * 之所以不共用模型：
 *   秒级调度每秒要处理数千个条目，模型里 30 多个字段 + 一堆 accessor
 *   的序列化/反序列化开销在此场景下不可接受。这里只保留时间轮与执行器
 *   真正需要的字段，保持纯数组级轻量。
 */
final class TaskEntry
{
    public int $taskId;
    public string $taskName;
    public PrecisionLevel $precision;
    public int $intervalSeconds;
    public string $command;
    /** @var array<int, mixed> */
    public array $parameters;
    public string $taskType;
    public ?int $nextFireAt = null;
    public int $version;
    public bool $withoutOverlapping;
    public bool $onOneServer;
    public bool $inMaintenanceMode;
    public int $concurrentLimit;
    public int $maxRuntime;
    public string $timeoutAction;
    public int $retryTimes;
    public int $retryInterval;

    /**
     * @param array<int, mixed> $parameters
     */
    private function __construct(
        int $taskId,
        string $taskName,
        PrecisionLevel $precision,
        int $intervalSeconds,
        string $command,
        array $parameters,
        string $taskType,
        int $version,
        bool $withoutOverlapping,
        bool $onOneServer,
        bool $inMaintenanceMode,
        int $concurrentLimit,
        int $maxRuntime,
        string $timeoutAction,
        int $retryTimes,
        int $retryInterval
    ) {
        $this->taskId = $taskId;
        $this->taskName = $taskName;
        $this->precision = $precision;
        $this->intervalSeconds = $intervalSeconds;
        $this->command = $command;
        $this->parameters = $parameters;
        $this->taskType = $taskType;
        $this->version = $version;
        $this->withoutOverlapping = $withoutOverlapping;
        $this->onOneServer = $onOneServer;
        $this->inMaintenanceMode = $inMaintenanceMode;
        $this->concurrentLimit = $concurrentLimit;
        $this->maxRuntime = $maxRuntime;
        $this->timeoutAction = $timeoutAction;
        $this->retryTimes = $retryTimes;
        $this->retryInterval = $retryInterval;
    }

    /**
     * 从模型数组 hydrate。
     * 这里的字段映射是调度器唯一允许的入口，方便日后模型字段改名时只改这一处。
     */
    public static function fromArray(array $row): self
    {
        $precisionRaw = (int) ($row['precision'] ?? PrecisionLevel::MINUTE->value);

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['task_name'] ?? ''),
            PrecisionLevel::tryFrom($precisionRaw) ?? PrecisionLevel::MINUTE,
            (int) ($row['interval_seconds'] ?? 60),
            (string) ($row['command'] ?? ''),
            self::decodeParameters($row['parameters'] ?? null),
            (string) ($row['task_type'] ?? 'command'),
            (int) ($row['version'] ?? 0),
            (bool) ($row['without_overlapping'] ?? false),
            (bool) ($row['on_one_server'] ?? false),
            (bool) ($row['in_maintenance_mode'] ?? false),
            (int) ($row['concurrent_limit'] ?? 0),
            (int) ($row['max_runtime'] ?? 0),
            (string) ($row['timeout_action'] ?? 'skip'),
            (int) ($row['retry_times'] ?? 0),
            (int) ($row['retry_interval'] ?? 60)
        );
    }

    /**
     * 参数解码：兼容 jsonb 数组与字符串两种存储形态。
     */
    private static function decodeParameters(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            if (trim($raw) === '') {
                return [];
            }
            return preg_split('/\s+/', trim($raw));
        }
        return [];
    }

    /**
     * 转成执行器期望的数组形态（与既有 TaskExecutor::execute 签名对齐）。
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->taskId,
            'task_name' => $this->taskName,
            'command' => $this->command,
            'parameters' => $this->parameters,
            'task_type' => $this->taskType,
            'without_overlapping' => $this->withoutOverlapping,
            'on_one_server' => $this->onOneServer,
            'in_maintenance_mode' => $this->inMaintenanceMode,
            'concurrent_limit' => $this->concurrentLimit,
            'max_runtime' => $this->maxRuntime,
            'timeout_action' => $this->timeoutAction,
            'retry_times' => $this->retryTimes,
            'retry_interval' => $this->retryInterval,
        ];
    }
}
