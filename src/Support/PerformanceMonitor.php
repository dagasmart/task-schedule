<?php

namespace DagaSmart\TaskSchedule\Support;

use Swow\Coroutine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * 性能监控器
 *
 * 实时采集调度器性能指标：
 * - 协程数
 * - 内存使用
 * - 执行耗时
 * - 队列深度
 */
class PerformanceMonitor
{
    private array $metrics = [];
    private float $startTime;
    private string $workerId;

    public function __construct(string $workerId)
    {
        $this->workerId = $workerId;
        $this->startTime = microtime(true);
    }

    /**
     * 记录任务执行
     */
    public function recordExecution(string $taskName, float $duration, bool $success, int $exitCode = 0): void
    {
        $this->metrics['executions'][] = [
            'task' => $taskName,
            'duration' => $duration,
            'success' => $success,
            'exit_code' => $exitCode,
            'timestamp' => microtime(true),
        ];

        // 保留最近 1000 条
        if (count($this->metrics['executions']) > 1000) {
            array_shift($this->metrics['executions']);
        }

        // 慢任务告警
        $threshold = config('schedule.monitoring.slow_task_threshold', 30);
        if ($duration > $threshold) {
            Log::warning("Slow task: {$taskName} took {$duration}s");
        }
    }

    /**
     * 获取当前快照
     */
    public function snapshot(): array
    {
        $executions = $this->metrics['executions'] ?? [];
        $durations = array_column($executions, 'duration');

        return [
            'worker_id' => $this->workerId,
            'uptime' => round(microtime(true) - $this->startTime, 2),
            'active_coroutines' => $this->getActiveCoroutineCount(),
            'total_executions' => count($executions),
            'success_count' => count(array_filter($executions, fn($e) => $e['success'])),
            'failed_count' => count(array_filter($executions, fn($e) => !$e['success'])),
            'avg_duration' => count($durations) > 0 ? round(array_sum($durations) / count($durations), 4) : 0,
            'max_duration' => count($durations) > 0 ? round(max($durations), 4) : 0,
            'min_duration' => count($durations) > 0 ? round(min($durations), 4) : 0,
            'p99_duration' => count($durations) > 0 ? round($this->percentile($durations, 99), 4) : 0,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => $this->getCpuUsage(),
        ];
    }

    /**
     * 上报指标
     */
    public function report(): void
    {
        $snapshot = $this->snapshot();
        $prefix = config('schedule.monitoring.metrics_prefix', 'task_schedule');

        // 写入缓存（供 API 读取）
        Cache::put(
            "metrics:{$this->workerId}",
            $snapshot,
            now()->addMinutes(5)
        );

        // 记录到日志
        Log::info("Metrics Report", [
            'worker' => $this->workerId,
            'executions' => $snapshot['total_executions'],
            'success' => $snapshot['success_count'],
            'failed' => $snapshot['failed_count'],
            'avg_duration' => $snapshot['avg_duration'],
            'memory_mb' => round($snapshot['memory_usage'] / 1024 / 1024, 2),
        ]);
    }

    /**
     * 获取活跃协程数
     */
    private function getActiveCoroutineCount(): int
    {
        // Swow 没有直接的协程计数 API
        // 这里返回估算值
        return count(Coroutine::getAll());
    }

    /**
     * 获取 CPU 使用率
     */
    private function getCpuUsage(): float
    {
        if (!is_readable('/proc/stat')) {
            return 0.0;
        }

        $stat1 = file('/proc/stat');
        usleep(100000); // 100ms
        $stat2 = file('/proc/stat');

        if (!$stat1 || !$stat2) {
            return 0.0;
        }

        $cpu1 = preg_split('/\s+/', trim(explode(' ', $stat1[0])[1] ?? ''));
        $cpu2 = preg_split('/\s+/', trim(explode(' ', $stat2[0])[1] ?? ''));

        if (count($cpu1) < 7 || count($cpu2) < 7) {
            return 0.0;
        }

        $total1 = array_sum(array_slice($cpu1, 0, 7));
        $total2 = array_sum(array_slice($cpu2, 0, 7));
        $idle1 = $cpu1[3];
        $idle2 = $cpu2[3];

        $total = $total2 - $total1;
        $idle = $idle2 - $idle1;

        if ($total === 0) {
            return 0.0;
        }

        return round(($total - $idle) / $total * 100, 2);
    }

    /**
     * 计算百分位
     */
    private function percentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ceil($percentile / 100 * count($values)) - 1;
        return $values[max(0, $index)];
    }

    /**
     * 重置统计
     */
    public function reset(): void
    {
        $this->metrics = [];
        $this->startTime = microtime(true);
    }
}
