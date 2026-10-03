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
 *
 * 秒级精度（precision=1）下，expression 字段会被解析：
 *   - *\/N * * * * *  → intervalSeconds = N（每 N 秒执行一次）
 *   - N * * * * *     → intervalSeconds = -N（每分钟第 N 秒，负数标记固定秒位）
 *   - 其他复杂格式    → 退化为使用 interval_seconds 字段的值
 *
 * 这样 `*\/5 * * * * *` 就能正确驱动 TimingWheel 每 5 秒触发一次，
 * 而 `5 * * * * *` 则驱动 TimingWheel 每分钟第 5 秒触发一次。
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
     * 从模型数组 hydrate。唯一入口，字段映射集中在此。
     */
    public static function fromArray(array $row): self
    {
        $precisionRaw = (int) ($row['precision'] ?? PrecisionLevel::MINUTE->value);
        $precision = PrecisionLevel::tryFrom($precisionRaw) ?? PrecisionLevel::MINUTE;

        $interval = (int) ($row['interval_seconds'] ?? 60);
        if ($precision === PrecisionLevel::SECOND && !empty($row['expression'])) {
            $exprInterval = self::extractSecondInterval((string) $row['expression']);
            if ($exprInterval !== null) {
                $interval = $exprInterval;
            }
        }

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['task_name'] ?? ''),
            $precision,
            $interval,
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
     * 从 cron expression 秒字段提取执行语义：
     *   - *       → 1（每 1 秒）
     *   - *\/N     → N（每 N 秒）
     *   - N       → -N（每分钟第 N 秒，负数标记固定秒位）
     *   - 其他    → null（退化为 interval_seconds 字段）
     */
    private static function extractSecondInterval(string $expression): ?int
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 6) {
            return null;
        }

        $second = $parts[0];

        if ($second === '*') {
            return 1;
        }

        if (preg_match('/^\*\/(\d+)$/', $second, $matches)) {
            $n = (int) $matches[1];
            if ($n >= 1 && $n <= 60) {
                return $n;
            }
            return null;
        }

        // 固定秒位（0-59）：负数标记，0 表示每分钟第 0 秒（即 :00）
        if (ctype_digit($second) && (int) $second >= 0 && (int) $second <= 59) {
            return -(int) $second; // 负数 = 固定秒位模式
        }

        return null;
    }

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
