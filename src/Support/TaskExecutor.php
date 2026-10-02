<?php

namespace DagaSmart\TaskSchedule\Support;

use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Enums\TaskStatus;
use DagaSmart\TaskSchedule\Models\TaskScheduleLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Swow\Coroutine;

/**
 * 任务执行器
 *
 * 负责实际执行调度任务，支持：
 * - 多种任务类型（command/job/url/shell）
 * - 超时控制
 * - 重试机制
 * - 并发限制
 * - 资源监控
 */
class TaskExecutor
{
    private string $workerId;
    private PerformanceMonitor $monitor;

    public function __construct(string $workerId, PerformanceMonitor $monitor)
    {
        $this->workerId = $workerId;
        $this->monitor = $monitor;
    }

    /**
     * 执行任务
     */
    public function execute(array $taskData): TaskExecutionResult
    {
        $taskId = $taskData['id'] ?? 0;
        $command = $taskData['command'] ?? '';
        $taskType = $taskData['task_type'] ?? 'command';

        $startTime = microtime(true);
        $log = $this->createLogRecord($taskData);

        try {
            // 执行前检查
            $this->beforeExecute($taskData);

            // 根据类型执行
            $exitCode = match ($taskType) {
                'command' => $this->executeCommand($taskData),
                'job' => $this->dispatchJob($taskData),
                'url' => $this->callUrl($taskData),
                'shell' => $this->executeShell($taskData),
                default => throw new \InvalidArgumentException("Unknown task type: {$taskType}"),
            };

            $duration = round(microtime(true) - $startTime, 4);
            $success = $exitCode === 0;

            // 更新日志
            $this->updateLogRecord($log, $success, $exitCode, $duration);

            // 记录监控
            $this->monitor->recordExecution($command, $duration, $success, $exitCode);

            // 重试逻辑
            if (!$success && ($taskData['retry_times'] ?? 0) > 0) {
                $this->scheduleRetry($taskData, $exitCode);
            }

            return new TaskExecutionResult($success, $exitCode, $duration);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 4);

            // 记录失败
            $this->updateLogRecord($log, false, 1, $duration, $e->getMessage());
            $this->monitor->recordExecution($command, $duration, false, 1);

            Log::error("Task execution failed [ID:{$taskId}]: " . $e->getMessage(), [
                'task_id' => $taskId,
                'command' => $command,
                'trace' => $e->getTraceAsString(),
            ]);

            return new TaskExecutionResult(false, 1, $duration, $e->getMessage());
        }
    }

    /**
     * 执行前检查
     */
    private function beforeExecute(array $taskData): void
    {
        // 检查环境
        $environments = $taskData['environments'] ?? null;
        if ($environments) {
            $envs = is_array($environments) ? $environments : json_decode($environments, true);
            if (!in_array(app()->environment(), $envs)) {
                throw new \RuntimeException(
                    "Task environment mismatch: current=" . app()->environment() .
                    ", required=" . implode(',', $envs)
                );
            }
        }

        // 检查维护模式
        if (!($taskData['in_maintenance_mode'] ?? false) && app()->isDownForMaintenance()) {
            throw new \RuntimeException("Application is in maintenance mode");
        }

        // 检查并发限制
        $limit = $taskData['concurrent_limit'] ?? 0;
        if ($limit > 0) {
            $runningCount = TaskScheduleLog::query()
                ->where('task_id', $taskData['id'])
                ->where('status', TaskStatus::RUNNING->value)
                ->count();

            if ($runningCount >= $limit) {
                throw new \RuntimeException("Concurrent limit reached: {$runningCount}/{$limit}");
            }
        }
    }

    /**
     * 执行 Artisan 命令
     */
    private function executeCommand(array $task): int
    {
        $command = trim($task['command'] ?? '');
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $params = $this->parseParameters($task['parameters'] ?? null);

        // 后台运行
        if ($task['in_background'] ?? false) {
            $this->runInBackground($command, $params, $task);
            return 0;
        }

        // 前台同步执行
        return $this->runForeground($command, $params, $task);
    }

    /**
     * 前台执行
     */
    private function runForeground(string $command, array $params, array $task): int
    {
        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

        if (!empty($params)) {
            $fullCommand .= ' ' . implode(' ', array_map('escapeshellarg', $params));
        }

        // 超时控制
        $timeout = $task['max_runtime'] ?? 0;
        if ($timeout > 0) {
            $fullCommand = sprintf('timeout %d %s', $timeout, $fullCommand);
        }

        // 输出重定向
        if (!empty($task['output_file_path'])) {
            $path = config('schedule.output.path') . '/' . $task['output_file_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $redirect = $task['output_append'] ? '>>' : '>';
            $fullCommand .= " {$redirect} {$path} 2>&1";
        }

        $exitCode = 0;
        system($fullCommand, $exitCode);

        return $exitCode;
    }

    /**
     * 后台运行
     */
    private function runInBackground(string $command, array $params, array $task): void
    {
        $fullCommand = sprintf('%s artisan %s', PHP_BINARY, $command);

        if (!empty($params)) {
            $fullCommand .= ' ' . implode(' ', array_map('escapeshellarg', $params));
        }

        // 输出重定向
        $outputRedirect = '';
        if (!empty($task['output_file_path'])) {
            $path = config('schedule.output.path') . '/' . $task['output_file_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $outputRedirect = $task['output_append'] ? " >> {$path} 2>&1" : " > {$path} 2>&1";
        }

        $fullCommand .= $outputRedirect . ' &';

        exec($fullCommand);
    }

    /**
     * 派发队列任务
     */
    private function dispatchJob(array $task): int
    {
        $jobClass = $task['command'] ?? '';
        $params = $this->parseParameters($task['parameters'] ?? null);

        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Job class not found: {$jobClass}");
        }

        $job = new $jobClass(...$params);
        dispatch($job);

        return 0;
    }

    /**
     * 调用 URL
     *
     * 安全说明：
     *   - 默认开启证书校验（CURLOPT_SSL_VERIFYPEER=true）。
     *     早期版本曾为 false，那是生产环境的安全反模式：等于主动接受中间人攻击，
     *     尤其对内部调度回调这种「带凭证、可写数据」的端点风险极高。
     *   - 自签名证书的正确做法是在 config 里指定 CURLOPT_CAINFO，而不是全局关闭校验。
     *   - 同一 worker 内复用 curl handle，避免每秒新建 TLS 握手的开销。
     */
    private function callUrl(array $task): int
    {
        $url = $task['command'] ?? '';
        $params = (array) ($task['parameters'] ?? []);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException("Invalid URL: {$url}");
        }

        $method = strtoupper((string) ($params['method'] ?? 'GET'));
        $headers = $params['headers'] ?? [];
        $body = $params['body'] ?? null;
        $timeout = max(1, (int) ($task['max_runtime'] ?? 30));

        // 只允许语义明确的动词，拒绝代理场景下的注入风险
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'], true)) {
            throw new \RuntimeException("Unsupported HTTP method: {$method}");
        }

        $options = [
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_CONNECTTIMEOUT   => 5,
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_POSTFIELDS       => is_array($body) ? json_encode($body) : $body,
            // 关键：保持证书校验开启
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            // 连接复用：秒级调度下复用 keep-alive，TLS 握手成本占比极高
            CURLOPT_FORBID_REUSE     => false,
            CURLOPT_FRESH_CONNECT    => false,
            CURLOPT_TCP_KEEPALIVE    => 1,
        ];

        // 显式指定 CA 证书（自签名/内网 CA 场景）
        $caFile = config('schedule.http.ca_file');
        if ($caFile && is_file($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        // 允许不校验证书（仅当配置明确开启时，默认关闭）
        if (config('schedule.http.allow_insecure', false)) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $httpCode === 0) {
            throw new \RuntimeException("URL call failed [errno={$errno}]: {$error}");
        }

        return $httpCode >= 200 && $httpCode < 300 ? 0 : 1;
    }

    /**
     * 执行 Shell 脚本
     */
    private function executeShell(array $task): int
    {
        $script = $task['command'] ?? '';

        if (!file_exists($script)) {
            throw new \RuntimeException("Shell script not found: {$script}");
        }

        if (!is_executable($script)) {
            throw new \RuntimeException("Shell script not executable: {$script}");
        }

        $exitCode = 0;
        system($script, $exitCode);

        return $exitCode;
    }

    /**
     * 解析参数
     */
    private function parseParameters($parameters): array
    {
        if ($parameters === null || $parameters === '') {
            return [];
        }

        if (is_array($parameters)) {
            return $parameters;
        }

        if (is_string($parameters)) {
            // 尝试 JSON 解析
            $decoded = json_decode($parameters, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (array) $decoded;
            }

            // 按空格分割
            return preg_split('/\s+/', trim($parameters));
        }

        return (array) $parameters;
    }

    /**
     * 创建日志记录
     */
    private function createLogRecord(array $task): TaskScheduleLog
    {
        return TaskScheduleLog::create([
            'task_id' => $task['id'] ?? 0,
            'task_name' => $task['task_name'] ?? '',
            'command' => $task['command'] ?? '',
            'description' => $task['description'] ?? '',
            'status' => TaskStatus::RUNNING->value,
            'worker_id' => $this->workerId,
            'started_at' => now(),
        ]);
    }

    /**
     * 更新日志记录
     */
    private function updateLogRecord(TaskScheduleLog $log, bool $success, int $exitCode, float $duration, ?string $error = null): void
    {
        $log->update([
            'status' => $success ? TaskStatus::SUCCESS->value : TaskStatus::FAILED->value,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'output' => $error ? json_encode(['error' => $error]) : null,
            'finished_at' => now(),
            'memory_peak' => memory_get_peak_usage(true),
        ]);
    }

    /**
     * 安排重试
     */
    private function scheduleRetry(array $task, int $exitCode): void
    {
        $retryTimes = $task['retry_times'] ?? 0;
        $retryInterval = $task['retry_interval'] ?? 60;

        // 记录重试日志
        DB::table('task_schedule_log')->insert([
            'task_id' => $task['id'] ?? 0,
            'task_name' => $task['task_name'] ?? '',
            'command' => $task['command'] ?? '',
            'description' => "Scheduled retry (attempt 1/{$retryTimes})",
            'status' => TaskStatus::RETRYING->value,
            'exit_code' => $exitCode,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 在协程中延迟重试
        Coroutine::run(function () use ($task, $retryInterval) {
            usleep($retryInterval * 1000000);
            $this->execute($task);
        });
    }
}

/**
 * 任务执行结果
 */
class TaskExecutionResult
{
    public bool $success;
    public int $exitCode;
    public float $duration;
    public ?string $error;

    public function __construct(bool $success, int $exitCode, float $duration, ?string $error = null)
    {
        $this->success = $success;
        $this->exitCode = $exitCode;
        $this->duration = $duration;
        $this->error = $error;
    }
}
