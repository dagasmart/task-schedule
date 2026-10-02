<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Engine;

use DagaSmart\TaskSchedule\Enums\PrecisionLevel;

/**
 * 分层时间轮（Hierarchical Timing Wheel）
 *
 * 设计目标：把「每秒扫全表」的 O(N) 调度降到 O(1) 槽位推进。
 * 结构：秒轮(60) → 分轮(60) → 时轮(24) → 日轮(31/天级桶)。
 *
 * 与 Cron 的关系：
 *   - precision=MINUTE 及以上：走 Laravel Schedule，本时间轮不参与
 *   - precision=SECOND：注册进秒轮，由 SwowScheduler 的 tick 驱动 advance()
 *
 * 为什么不用一层大数组：
 *   单层的 30 天秒级数组是 2592000 个槽，内存浪费且重算成本高；
 *   四层嵌套后每次 tick 只推进一档秒针，进位时把上一层桶整体降级重投。
 *
 * 为什么是 O(1)：
 *   插入 = 计算目标槽位 + 链表 push；推进 = 取当前槽 + 清空链表。
 *   任务量增长不增加单 tick CPU 开销，只增加单槽链表长度。
 */
final class TimingWheel
{
    private const int SECONDS_PER_WHEEL = 60;
    private const int MINUTES_PER_WHEEL = 60;
    private const int HOURS_PER_WHEEL   = 24;

    /** 秒轮：每秒一个槽 */
    private array $secondSlots = [];

    /** 分轮：每分钟一个槽，存「还需再等几分钟」的任务 */
    private array $minuteSlots = [];

    /** 时轮：每小时一个槽 */
    private array $hourSlots = [];

    /** 日级桶：按日期字符串索引，进位时整体降级 */
    private array $dayBuckets = [];

    /** 已注册的秒级任务快照：taskId => TaskEntry，用于重算与取消 */
    private array $registry = [];

    /** 当前指针 */
    private int $secondCursor = 0;
    private int $minuteCursor = 0;
    private int $hourCursor   = 0;

    /** 统计 */
    private int $totalScheduled = 0;
    private int $totalFired     = 0;
    private int $totalCancelled = 0;

    public function __construct()
    {
        for ($i = 0; $i < self::SECONDS_PER_WHEEL; $i++) {
            $this->secondSlots[$i] = [];
        }
        for ($i = 0; $i < self::MINUTES_PER_WHEEL; $i++) {
            $this->minuteSlots[$i] = [];
        }
        for ($i = 0; $i < self::HOURS_PER_WHEEL; $i++) {
            $this->hourSlots[$i] = [];
        }
    }

    /**
     * 注册一个秒级任务。
     * 语义：从「现在」起，每 $interval 秒执行一次。
     */
    public function schedule(TaskEntry $entry, int $nowTs): void
    {
        if ($entry->precision !== PrecisionLevel::SECOND) {
            throw new \InvalidArgumentException(
                'TimingWheel only accepts SECOND precision tasks'
            );
        }

        $interval = max(1, $entry->intervalSeconds);
        // 首次触发：取下一个对齐的 interval 边界，避免全部挤在第 0 秒
        $firstFireAt = $this->nextAlignedFireTime($nowTs, $interval);
        $entry->nextFireAt = $firstFireAt;

        $this->place($entry, $firstFireAt, $nowTs);
        $this->registry[$entry->taskId] = $entry;
        $this->totalScheduled++;
    }

    /**
     * 取消注册（任务停用 / 删除）
     */
    public function cancel(int $taskId): bool
    {
        if (!isset($this->registry[$taskId])) {
            return false;
        }
        unset($this->registry[$taskId]);
        $this->totalCancelled++;
        return true;
    }

    /**
     * 推进一格（每次 tick 调用一次）。
     * 返回本次到期应执行的任务列表。
     */
    public function advance(int $nowTs): array
    {
        $fired = [];

        // 1) 秒针推进：当前秒槽全部到期
        $slot = $this->secondSlots[$this->secondCursor];
        foreach ($slot as $entry) {
            if (!$this->isStillValid($entry)) {
                continue;
            }
            $fired[] = $entry;
            $this->rearm($entry, $nowTs);
        }
        $this->secondSlots[$this->secondCursor] = [];

        // 2) 秒针进位：处理分钟级桶降级
        $this->secondCursor = ($this->secondCursor + 1) % self::SECONDS_PER_WHEEL;
        if ($this->secondCursor === 0) {
            $this->cascadeMinutes($nowTs);
        }

        $this->totalFired += count($fired);
        return $fired;
    }

    /**
     * 返回当前所有已注册任务的快照（用于诊断 / 热重载比对）
     */
    public function snapshot(): array
    {
        return array_values($this->registry);
    }

    /**
     * 判断某 taskId 是否已注册（用于热重载时判断新增/续存）
     */
    public function has(int $taskId): bool
    {
        return isset($this->registry[$taskId]);
    }

    /**
     * 返回当前所有已注册任务的 ID 列表（用于比对已停用/已删除的任务）
     */
    public function ids(): array
    {
        return array_keys($this->registry);
    }

    public function stats(): array
    {
        return [
            'scheduled'  => $this->totalScheduled,
            'fired'      => $this->totalFired,
            'cancelled'  => $this->totalCancelled,
            'registered' => count($this->registry),
            'second_ptr' => $this->secondCursor,
            'minute_ptr' => $this->minuteCursor,
            'hour_ptr'   => $this->hourCursor,
        ];
    }

    /**
     * 计算下一个对齐的触发时刻。
     * 对齐的意义：interval=5 的任务分布在第 0/5/10... 秒，
     * 而不是全挤在某个时间戳上，避免瞬间并发尖峰。
     */
    private function nextAlignedFireTime(int $nowTs, int $interval): int
    {
        if ($interval >= 60) {
            // 分钟级以上：对齐到分钟边界
            $base = (int) ceil($nowTs / 60) * 60;
            return $base + (int) (ceil(($nowTs - $base) / $interval) * $interval);
        }
        $remainder = $nowTs % $interval;
        if ($remainder === 0) {
            return $nowTs + $interval;
        }
        return $nowTs + ($interval - $remainder);
    }

    /**
     * 把任务放到正确的层级槽位里。
     * 关键：能放秒轮就放秒轮，放不下的逐级上抛。
     */
    private function place(TaskEntry $entry, int $fireAt, int $nowTs): void
    {
        $delay = $fireAt - $nowTs;
        if ($delay <= 0) {
            // 已经过期：丢进当前秒槽，本轮立即执行
            $this->secondSlots[$this->secondCursor][] = $entry;
            return;
        }

        if ($delay < self::SECONDS_PER_WHEEL) {
            // 秒轮内：直接定位
            $slot = ($this->secondCursor + $delay) % self::SECONDS_PER_WHEEL;
            $this->secondSlots[$slot][] = $entry;
            return;
        }

        $delayMinutes = (int) floor($delay / 60);
        if ($delayMinutes < self::MINUTES_PER_WHEEL) {
            $slot = ($this->minuteCursor + $delayMinutes) % self::MINUTES_PER_WHEEL;
            $this->minuteSlots[$slot][] = $entry;
            return;
        }

        $delayHours = (int) floor($delayMinutes / 60);
        if ($delayHours < self::HOURS_PER_WHEEL) {
            $slot = ($this->hourCursor + $delayHours) % self::HOURS_PER_WHEEL;
            $this->hourSlots[$slot][] = $entry;
            return;
        }

        // 超过 24 小时：进日级桶，按日期降级
        $dayKey = date('Y-m-d', $fireAt);
        $this->dayBuckets[$dayKey][] = $entry;
    }

    /**
     * 秒针归零时调用：分钟轮推进一格，把该分钟桶降级到秒轮。
     */
    private function cascadeMinutes(int $nowTs): void
    {
        $slot = $this->minuteSlots[$this->minuteCursor];
        foreach ($slot as $entry) {
            $this->place($entry, $entry->nextFireAt, $nowTs);
        }
        $this->minuteSlots[$this->minuteCursor] = [];

        $this->minuteCursor = ($this->minuteCursor + 1) % self::MINUTES_PER_WHEEL;
        if ($this->minuteCursor === 0) {
            $this->cascadeHours($nowTs);
        }
    }

    /**
     * 分针归零时调用：小时轮推进一格，降级到分轮。
     */
    private function cascadeHours(int $nowTs): void
    {
        $slot = $this->hourSlots[$this->hourCursor];
        foreach ($slot as $entry) {
            $this->place($entry, $entry->nextFireAt, $nowTs);
        }
        $this->hourSlots[$this->hourCursor] = [];

        $this->hourCursor = ($this->hourCursor + 1) % self::HOURS_PER_WHEEL;
        if ($this->hourCursor === 0) {
            $this->cascadeDays($nowTs);
        }
    }

    /**
     * 时针归零时调用：日级桶降级。
     * 只处理今天与昨天的桶（昨天的是漏跑任务，需补执行）。
     */
    private function cascadeDays(int $nowTs): void
    {
        $today = date('Y-m-d', $nowTs);
        $yesterday = date('Y-m-d', $nowTs - 86400);

        foreach ([$yesterday, $today] as $key) {
            if (!isset($this->dayBuckets[$key])) {
                continue;
            }
            foreach ($this->dayBuckets[$key] as $entry) {
                $this->place($entry, $entry->nextFireAt, $nowTs);
            }
            unset($this->dayBuckets[$key]);
        }

        // 清理掉更早的桶（跨多日未启动的 worker，直接丢弃，避免启动瞬间雪崩）
        foreach (array_keys($this->dayBuckets) as $staleKey) {
            foreach ($this->dayBuckets[$staleKey] as $entry) {
                // 重新计算 nextFireAt，跳过已过去的触发点
                $entry->nextFireAt = $this->nextAlignedFireTime(
                    $nowTs,
                    max(1, $entry->intervalSeconds)
                );
                $this->place($entry, $entry->nextFireAt, $nowTs);
            }
            unset($this->dayBuckets[$staleKey]);
        }
    }

    /**
     * 任务执行完后重新挂起到下一轮。
     */
    private function rearm(TaskEntry $entry, int $nowTs): void
    {
        if (!$this->isStillValid($entry)) {
            return;
        }
        $interval = max(1, $entry->intervalSeconds);
        $entry->nextFireAt = $nowTs + $interval;
        $this->place($entry, $entry->nextFireAt, $nowTs);
    }

    /**
     * 任务是否仍有效：未取消、未停用。
     */
    private function isStillValid(TaskEntry $entry): bool
    {
        return isset($this->registry[$entry->taskId]);
    }
}
