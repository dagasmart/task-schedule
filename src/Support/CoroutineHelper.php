<?php

namespace DagaSmart\TaskSchedule\Support;

use Swow\Coroutine;
use Swow\Sync\WaitGroup;
use Swow\Channel;

/**
 * 协程工具类
 *
 * 提供高级协程操作封装：
 * - 并发控制
 * - 超时管理
 * - 批量执行
 * - 优雅退出
 */
class CoroutineHelper
{
    /**
     * 并发执行多个任务（有界并发）
     */
    public static function concurrent(array $tasks, int $maxConcurrency = 100, int $timeout = 0): array
    {
        $channel = new Channel($maxConcurrency);
        $results = [];
        $wg = new WaitGroup(count($tasks));

        foreach ($tasks as $index => $task) {
            $channel->push(true); // 获取许可

            Coroutine::run(function () use ($task, $index, $channel, $wg, &$results, $timeout) {
                defer(function () use ($channel, $wg) {
                    $channel->pop();
                    $wg->done();
                });

                try {
                    if ($timeout > 0) {
                        $results[$index] = self::withTimeout($task, $timeout);
                    } else {
                        $results[$index] = $task();
                    }
                } catch (\Throwable $e) {
                    $results[$index] = new \RuntimeException($e->getMessage(), $e->getCode(), $e);
                }
            });
        }

        $wg->wait();
        ksort($results);

        return $results;
    }

    /**
     * 带超时的协程执行
     */
    public static function withTimeout(callable $task, int $timeoutMs): mixed
    {
        $channel = new Channel(1);
        $result = null;
        $exception = null;

        Coroutine::run(function () use ($task, $channel, &$result, &$exception) {
            try {
                $result = $task();
            } catch (\Throwable $e) {
                $exception = $e;
            } finally {
                $channel->push(true);
            }
        });

        $received = $channel->pop($timeoutMs);

        if ($received === false) {
            throw new \RuntimeException("Task timed out after {$timeoutMs}ms");
        }

        if ($exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * 批量执行（分批处理）
     */
    public static function batch(array $items, callable $handler, int $batchSize = 100, int $maxConcurrency = 10): array
    {
        $batches = array_chunk($items, $batchSize);
        $tasks = [];

        foreach ($batches as $batch) {
            $tasks[] = function () use ($batch, $handler) {
                return array_map($handler, $batch);
            };
        }

        return self::concurrent($tasks, $maxConcurrency);
    }

    /**
     * 重试机制
     */
    public static function retry(callable $task, int $maxAttempts = 3, int $delayMs = 100, float $backoff = 2.0): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $task();
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                $delay = (int) ($delayMs * pow($backoff, $attempt - 1));
                usleep($delay * 1000);
            }
        }
    }

    /**
     * 创建有界协程池
     */
    public static function createPool(int $size, callable $worker): CoroutinePool
    {
        return new CoroutinePool($size, $worker);
    }

    /**
     * 检查是否在协程中
     */
    public static function isInCoroutine(): bool
    {
        return Coroutine::getCurrent() !== null;
    }

    /**
     * 安全的 defer 执行
     */
    public static function defer(callable $callback): void
    {
        if (self::isInCoroutine()) {
            defer($callback);
        } else {
            // 非协程环境，直接执行
            try {
                $callback();
            } catch (\Throwable $e) {
                // 忽略错误
            }
        }
    }
}
