<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 分布式锁管理器
 *
 * 支持两种锁机制：
 * 1. PostgreSQL Advisory Lock（推荐，零额外依赖）
 * 2. Cache Lock（Redis/Memcached 等）
 */
class DistributedLock
{
    private string $name;
    private int $timeout;
    private int $lockKey;
    private bool $acquired = false;
    private string $driver;

    /**
     * @param string $name 锁名称
     * @param int $timeout 超时时间（秒）
     * @param string $driver 锁驱动: advisory|cache
     */
    public function __construct(string $name, int $timeout = 300, string $driver = 'advisory')
    {
        $this->name = $name;
        $this->timeout = $timeout;
        $this->driver = $driver;
        $this->lockKey = $this->generateLockKey($name);
    }

    /**
     * 尝试获取锁
     */
    public function acquire(): bool
    {
        return match ($this->driver) {
            'advisory' => $this->acquireAdvisoryLock(),
            'cache' => $this->acquireCacheLock(),
            default => throw new \InvalidArgumentException("Unsupported lock driver: {$this->driver}"),
        };
    }

    /**
     * 获取锁并执行回调
     */
    public function withLock(callable $callback): mixed
    {
        if (!$this->acquire()) {
            throw new \RuntimeException("Failed to acquire lock: {$this->name}");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * 释放锁
     */
    public function release(): bool
    {
        if (!$this->acquired) {
            return false;
        }

        $result = match ($this->driver) {
            'advisory' => $this->releaseAdvisoryLock(),
            'cache' => $this->releaseCacheLock(),
            default => false,
        };

        $this->acquired = !$result;
        return $result;
    }

    /**
     * 获取 PostgreSQL Advisory Lock
     */
    private function acquireAdvisoryLock(): bool
    {
        try {
            // 使用事务级锁（自动释放）
            $result = DB::selectOne(
                'SELECT pg_try_advisory_xact_lock(?) as acquired',
                [$this->lockKey]
            );

            $this->acquired = (bool) ($result->acquired ?? false);

            if ($this->acquired) {
                // 记录锁信息到分发表
                $this->recordLockAcquisition();
            }

            return $this->acquired;
        } catch (\Throwable $e) {
            // 非 PostgreSQL 环境回退到 Cache
            return $this->acquireCacheLock();
        }
    }

    /**
     * 释放 Advisory Lock（事务级锁自动释放）
     */
    private function releaseAdvisoryLock(): bool
    {
        // 事务级 advisory lock 在事务提交/回滚时自动释放
        // 这里更新分发记录
        try {
            DB::table('task_schedule_dispatch')
                ->where('lock_key', $this->lockKey)
                ->whereNull('finished_at')
                ->update(['finished_at' => now()]);
        } catch (\Throwable $e) {
            // 忽略错误
        }

        return true;
    }

    /**
     * 获取 Cache Lock
     */
    private function acquireCacheLock(): bool
    {
        return Cache::lock($this->name, $this->timeout)->get();
    }

    /**
     * 释放 Cache Lock
     */
    private function releaseCacheLock(): bool
    {
        return Cache::lock($this->name)->forceRelease();
    }

    /**
     * 记录锁获取信息
     */
    private function recordLockAcquisition(): void
    {
        try {
            DB::table('task_schedule_dispatch')->insert([
                'task_id' => abs($this->lockKey % 1000000),
                'dispatch_id' => uniqid('lock_', true),
                'status' => 1,
                'worker_id' => gethostname() . '_' . getmypid(),
                'server_id' => gethostname(),
                'lock_key' => $this->lockKey,
                'scheduled_at' => now(),
                'started_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 忽略错误
        }
    }

    /**
     * 生成锁键
     *
     * 使用 bigint 避免冲突：
     * (namespace << 32) | (hash & 0xFFFFFFFF)
     */
    private function generateLockKey(string $name): int
    {
        $namespace = config('schedule.locking.namespace', 0x5441534B);
        $hash = $this->hashString($name);

        return ($namespace << 32) | ($hash & 0xFFFFFFFF);
    }

    /**
     * 字符串哈希
     */
    private function hashString(string $str): int
    {
        // 使用 md5 前 8 字符作为 32 位整数
        $hex = substr(md5($str), 0, 8);
        return hexdec($hex);
    }

    /**
     * 析构时释放锁
     */
    public function __destruct()
    {
        if ($this->acquired) {
            $this->release();
        }
    }
}
