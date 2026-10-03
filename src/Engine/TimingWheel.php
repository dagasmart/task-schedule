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
 *
 * ==================== v3 修复说明 ====================
 * 问题：固定秒位模式（如 5 * * * * *）出现跳 2 分钟执行的情况。
 *
 * 根因：
 *   1. nextAlignedFireTime() 用 floor($nowTs/60)*60 + 5 锚定，
 *      当 nowTs 已过第 5 秒时 +60，但逻辑与 rearm() 分叉，
 *      两者对"下一分钟"的计算口径不一致。
 *   2. rearm() 用 floor + 比较 > 的方式，当 nowTs 恰好等于
 *      targetThisMinute 时走 else 分支，多跳一分钟。
 *   3. place() 对固定秒位任务用 delay % 60 算槽位，当 delay
 *      不是精确 60 倍数时放错槽，导致整分钟漏触发。
 *
 * 修复方案：
 *   1. nextAlignedFireTime() 和 rearm() 统一用 ceil($nowTs/60)*60
 *      锚定到下一分钟，边界情况（target <= nowTs）再跳一分钟。
 *   2. place() 对固定秒位任务直接映射到 $fireAt % 60 槽位，
 *      不走 delay 间接计算。
 */
final class TimingWheel
{
    private const int SECONDS_PER_WHEEL = 60;
    private const int MINUTES_PER_WHEEL = 60;
    private const int HOURS_PER_WHEEL   = 24;

    private array $secondSlots = [];
    private array $minuteSlots = [];
    private array $hourSlots = [];
    private array $dayBuckets = [];
    private array $registry = [];

    private int $secondCursor = 0;
    private int $minuteCursor = 0;
    private int $hourCursor   = 0;

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
     * 注册秒级任务。首次触发时间由 nextAlignedFireTime() 计算。
     */
    public function schedule(TaskEntry $entry, int $nowTs): void
    {
        if ($entry->precision !== PrecisionLevel::SECOND) {
            throw new \InvalidArgumentException('TimingWheel only accepts SECOND precision tasks');
        }

        $interval = max(1, abs($entry->intervalSeconds));
        $entry->nextFireAt = $this->nextAlignedFireTime($nowTs, $entry->intervalSeconds);

        $this->place($entry, $entry->nextFireAt, $nowTs);
        $this->registry[$entry->taskId] = $entry;
        $this->totalScheduled++;
    }

    /**
     * 取消任务：从 registry + 所有层级 slot 中彻底清除。
     */
    public function cancel(int $taskId): bool
    {
        if (!isset($this->registry[$taskId])) {
            return false;
        }

        // 从秒槽清除
        for ($i = 0; $i < self::SECONDS_PER_WHEEL; $i++) {
            foreach ($this->secondSlots[$i] as $key => $e) {
                if ($e->taskId === $taskId) {
                    unset($this->secondSlots[$i][$key]);
                }
            }
        }

        // 从分槽清除
        for ($i = 0; $i < self::MINUTES_PER_WHEEL; $i++) {
            foreach ($this->minuteSlots[$i] as $key => $e) {
                if ($e->taskId === $taskId) {
                    unset($this->minuteSlots[$i][$key]);
                }
            }
        }

        // 从时槽清除
        for ($i = 0; $i < self::HOURS_PER_WHEEL; $i++) {
            foreach ($this->hourSlots[$i] as $key => $e) {
                if ($e->taskId === $taskId) {
                    unset($this->hourSlots[$i][$key]);
                }
            }
        }

        // 从日桶清除
        foreach ($this->dayBuckets as $dayKey => $bucket) {
            foreach ($bucket as $key => $e) {
                if ($e->taskId === $taskId) {
                    unset($this->dayBuckets[$dayKey][$key]);
                }
            }
        }

        unset($this->registry[$taskId]);
        $this->totalCancelled++;
        return true;
    }

    /**
     * 推进时间轮，返回本次到期应执行的任务列表。
     * 基于 $nowTs 计算目标秒位，支持追赶/跳跃。
     */
    public function advance(int $nowTs): array
    {
        $fired = [];
        $firedTaskIds = [];

        $targetSecond = $nowTs % self::SECONDS_PER_WHEEL;

        $steps = 0;
        $maxSteps = self::SECONDS_PER_WHEEL;

        while ($this->secondCursor !== $targetSecond && $steps < $maxSteps) {
            $this->processSecondSlot($nowTs, $fired, $firedTaskIds);
            $this->secondCursor = ($this->secondCursor + 1) % self::SECONDS_PER_WHEEL;
            $steps++;

            if ($this->secondCursor === 0) {
                $this->cascadeMinutes($nowTs);
            }
        }

        if ($steps === 0) {
            $this->processSecondSlot($nowTs, $fired, $firedTaskIds);
        }

        $this->totalFired += count($fired);
        return $fired;
    }

    /**
     * 处理当前秒槽：到期则 fire，未到期则重放。
     */
    private function processSecondSlot(int $nowTs, array &$fired, array &$firedTaskIds): void
    {
        $slot = &$this->secondSlots[$this->secondCursor];

        foreach ($slot as $key => $entry) {
            if (!$this->isStillValid($entry)) {
                unset($slot[$key]);
                continue;
            }

            if (isset($firedTaskIds[$entry->taskId])) {
                unset($slot[$key]);
                continue;
            }

            if ($entry->nextFireAt === null || $entry->nextFireAt > $nowTs) {
                // 未到期：从当前槽移除，重新 place 到正确槽位
                unset($slot[$key]);
                $this->place($entry, $entry->nextFireAt ?? $nowTs, $nowTs);
                continue;
            }

            $fired[] = $entry;
            $firedTaskIds[$entry->taskId] = true;
            unset($slot[$key]);
            $this->rearm($entry, $nowTs);
        }
    }

    /**
     * 按 taskId 获取已注册条目（供热重载比对 interval 变化）。
     */
    public function getEntry(int $taskId): ?TaskEntry
    {
        return $this->registry[$taskId] ?? null;
    }

    public function has(int $taskId): bool
    {
        return isset($this->registry[$taskId]);
    }

    public function ids(): array
    {
        return array_keys($this->registry);
    }

    public function snapshot(): array
    {
        return array_values($this->registry);
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
     * 计算首次/下次触发时间。
     *   - 负数 interval → 固定秒位模式（每分钟第 |N| 秒）
     *   - >= 60        → 分钟级对齐
     *   - 正数 < 60    → 间隔对齐
     */
    private function nextAlignedFireTime(int $nowTs, int $interval): int
    {
        if ($interval < 0) {
            // ✅ 固定秒位：统一锚定到下一分钟的第 |interval| 秒
            $fixedSecond = -$interval;
            $nextMinute = (int) ceil($nowTs / 60) * 60;
            $target = $nextMinute + $fixedSecond;

            // 边界：如果 nowTs 恰好 >= target（同一秒或已过），跳到再下一分钟
            if ($target <= $nowTs) {
                $target = $nextMinute + 60 + $fixedSecond;
            }

            return $target;
        }

        if ($interval >= 60) {
            $nextMinute = (int) ceil($nowTs / 60) * 60;
            if ($nextMinute <= $nowTs) {
                $nextMinute = $nowTs + 60;
            }
            return $nextMinute;
        }

        $remainder = $nowTs % $interval;
        if ($remainder === 0) {
            return $nowTs + $interval;
        }
        return $nowTs + ($interval - $remainder);
    }

    /**
     * 把任务放到正确的层级槽位。
     */
    private function place(TaskEntry $entry, int $fireAt, int $nowTs): void
    {
        // ✅ 固定秒位模式：直接映射到秒轮的对应槽，不走 delay 间接计算
        if ($entry->intervalSeconds < 0) {
            $slot = $fireAt % self::SECONDS_PER_WHEEL;
            $this->secondSlots[$slot][] = $entry;
            return;
        }

        $delay = $fireAt - $nowTs;
        if ($delay <= 0) {
            $this->secondSlots[$this->secondCursor][] = $entry;
            return;
        }

        if ($delay < self::SECONDS_PER_WHEEL) {
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

        $dayKey = date('Y-m-d', $fireAt);
        $this->dayBuckets[$dayKey][] = $entry;
    }

    /**
     * 执行完后重新挂起。
     *   - 负数 interval → 固定秒位（下一分钟第 N 秒）
     *   - 正数         → nowTs + interval
     */
    private function rearm(TaskEntry $entry, int $nowTs): void
    {
        if (!$this->isStillValid($entry)) {
            return;
        }

        $interval = $entry->intervalSeconds;

        if ($interval < 0) {
            // ✅ 与 nextAlignedFireTime 统一逻辑
            $fixedSecond = -$interval;
            $nextMinute = (int) ceil($nowTs / 60) * 60;
            $target = $nextMinute + $fixedSecond;

            if ($target <= $nowTs) {
                $target = $nextMinute + 60 + $fixedSecond;
            }

            $entry->nextFireAt = $target;
        } elseif ($interval >= 60) {
            $nextMinute = (int) ceil($nowTs / 60) * 60;
            if ($nextMinute <= $nowTs) {
                $nextMinute = $nowTs + 60;
            }
            $entry->nextFireAt = $nextMinute;
        } else {
            $entry->nextFireAt = $nowTs + $interval;
        }

        $this->place($entry, $entry->nextFireAt, $nowTs);
    }

    private function isStillValid(TaskEntry $entry): bool
    {
        return isset($this->registry[$entry->taskId]);
    }

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

        // 清理更早的桶，重新计算触发时间
        foreach (array_keys($this->dayBuckets) as $staleKey) {
            foreach ($this->dayBuckets[$staleKey] as $entry) {
                $entry->nextFireAt = $this->nextAlignedFireTime(
                    $nowTs,
                    $entry->intervalSeconds
                );
                $this->place($entry, $entry->nextFireAt, $nowTs);
            }
            unset($this->dayBuckets[$staleKey]);
        }
    }
}
