<?php

namespace DagaSmart\TaskSchedule\Support;

use Swow\Coroutine;
use Swow\Channel;

/**
 * 协程池
 *
 * 维护固定数量的常驻协程 Worker，
 * 通过 Channel 接收任务并执行
 */
class CoroutinePool
{
    private Channel $taskChannel;
    private array $workers = [];
    private bool $running = false;
    private int $workerCount;
    private mixed $workerLogic;

    public function __construct(int $workerCount, callable $workerLogic)
    {
        $this->workerCount = $workerCount;
        $this->workerLogic = $workerLogic;
        $this->taskChannel = new Channel($workerCount * 2);
    }

    /**
     * 启动协程池
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->workers[] = Coroutine::run(function () {
                $this->workerLoop();
            });
        }
    }

    /**
     * Worker 循环
     */
    private function workerLoop(): void
    {
        while ($this->running) {
            $task = $this->taskChannel->pop();

            if ($task === null) {
                break;
            }

            try {
                ($this->workerLogic)($task);
            } catch (\Throwable $e) {
                // 记录错误但不中断 Worker
                error_log("Worker error: " . $e->getMessage());
            }
        }
    }

    /**
     * 提交任务
     */
    public function submit(mixed $task): void
    {
        if (!$this->running) {
            throw new \RuntimeException("Pool is not running");
        }

        $this->taskChannel->push($task);
    }

    /**
     * 停止协程池
     */
    public function stop(): void
    {
        $this->running = false;
        $this->taskChannel->close();
    }

    /**
     * 获取活跃 Worker 数
     */
    public function getActiveCount(): int
    {
        return count(array_filter($this->workers, fn($c) => $c->isAlive()));
    }

    /**
     * 等待所有任务完成
     */
    public function wait(): void
    {
        // 等待通道清空
        while ($this->taskChannel->getLength() > 0) {
            usleep(1000);
        }
    }
}
