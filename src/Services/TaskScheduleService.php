<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Services;

use Cron\CronExpression;
use DagaSmart\BizAdmin\Admin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;
use DagaSmart\TaskSchedule\Enums\TaskStatus;

/**
 * 任务调度服务类
 */
class TaskScheduleService extends AdminService
{
    protected string $modelName = TaskSchedule::class;

    /**
     * 排序处理
     */
    public function sortable($query)
    {
        if (request()->orderBy) {
            parent::sortable($query);
        } else {
            $query->orderBy('priority', 'desc')
                ->orderBy($this->primaryKey());
        }
    }

    /**
     * 任务类型
     * @return string[]
     */
    public function taskTypeOption(): array
    {
        return [
            ['label' => 'Artisan 命令', 'value' => 'command'],
            ['label' => 'Queue 队列', 'value' => 'job'],
            ['label' => 'HTTP 请求', 'value' => 'url'],
            ['label' => 'Shell 脚本', 'value' => 'shell'],
            ['label' => 'Call 闭包', 'value' => 'closure'],
        ];
    }

    /**
     * Map任务类型 (派生)
     * @return string[]
     */
    public function taskTypeMap(): array
    {
        $colors = [
            'command' => 'label-primary',
            'job'     => 'label-success',
            'url'     => 'label-warning',
            'shell'   => 'label-info',
            'closure' => 'label-default',
        ];

        $map = [];
        foreach ($this->taskTypeOption() as $item) {
            $value = $item['value'];
            $label = $item['label'];
            $color = $colors[$value] ?? 'label-default';
            $map[$value] = '<span class="label ' . $color . '">' . $label . '</span>';
        }

        return $map;
    }

    /**
     * 获取任务分组（树形结构）
     */
    public function getGroups(): array
    {
        $data = $this->getModel()->getGroups()->toArray();
        return array2tree($data);
    }

    /**
     * 环境选项
     */
    public function envOption(): array
    {
        return [
            ['label' => '本地环境', 'value' => 'local'],
            ['label' => '开发环境', 'value' => 'development'],
            ['label' => '测试环境', 'value' => 'testing'],
            ['label' => '预发布环境', 'value' => 'staging'],
            ['label' => '生产环境', 'value' => 'production'],
        ];
    }

    /**
     * 状态选项
     */
    public function statusOption(): array
    {
        return $this->getModel()->statusOption();
    }

    /**
     * 精度级别选项
     */
    public function precisionOption(): array
    {
        return [
            ['label' => '秒级 (0~59秒)', 'value' => PrecisionLevel::SECOND->value],
            ['label' => '分级 (0~59分)', 'value' => PrecisionLevel::MINUTE->value],
            ['label' => '时级 (0~23时)', 'value' => PrecisionLevel::HOUR->value],
            ['label' => '天级 (每天)',     'value' => PrecisionLevel::DAY->value],
            ['label' => '周级 (每周)',     'value' => PrecisionLevel::WEEK->value],
            ['label' => '月级 (每月)',     'value' => PrecisionLevel::MONTH->value],
        ];
    }

    /**
     * Map精度级别选项 (派生)
     */
    public function precisionMap(): array
    {
        $colors = [
            PrecisionLevel::SECOND->value => 'label-danger',
            PrecisionLevel::MINUTE->value => 'label-warning',
            PrecisionLevel::HOUR->value   => 'label-info',
            PrecisionLevel::DAY->value    => 'label-success',
            PrecisionLevel::WEEK->value   => 'label-primary',
            PrecisionLevel::MONTH->value  => 'label-default',
        ];

        $map = [];
        foreach ($this->precisionOption() as $item) {
            $value = (string) $item['value'];   // mapping key 是字符串
            $label = $item['label'];
            // 截短显示，只取括号前的文字
            $short = explode(' ', $label)[0];   // "秒级" / "分级" ...
            $color = $colors[$item['value']] ?? 'label-default';
            $map[$value] = '<span class="label ' . $color . '">' . $short . '</span>';
        }

        return $map;
    }

    /**
     * 保存前处理
     */
    public function saving(&$data, $primaryKey = ''): void
    {
        $admin = admin_user();
        admin_abort_if(!$admin, '请先登录');

        // Cron 表达式校验
        if (!empty($data['expression'])) {
            if (!$this->validateCronExpression($data['expression'])) {
                admin_abort('执行时间不是合法的 CRON 表达式');
            }
        }

        // 参数 JSON 化
        if (isset($data['parameters']) && !empty($data['parameters'])) {
            if (is_string($data['parameters'])) {
                // 尝试解析为 JSON
                json_decode($data['parameters']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // 不是 JSON，按空格分割成数组
                    $data['parameters'] = explode(' ', trim($data['parameters']));
                }
            }
        }

        // 环境设置
        if (isset($data['environments']) && is_string($data['environments'])) {
            $data['environments'] = explode(',', $data['environments']);
        }

        // 设置创建人
        if (empty($primaryKey)) {
            $data['creator_id'] = $admin->id;
            $data['creator'] = $admin->name;
        }

        // 计算下次执行时间
        if (!empty($data['expression']) && !empty($data['active'])) {
            $task = $this->getModel();
            foreach ($data as $key => $value) {
                $task->setAttribute($key, $value);
            }
            $nextRun = $task->calculateNextRun();
            $data['next_run_at'] = $nextRun;
        }
    }

    /**
     * 保存后处理
     */
    public function saved($model, $data = []): void
    {
        // 清除相关缓存
        $cacheKey = "task_schedule:{$model->id}";
        cache()->forget($cacheKey);

        // 自增版本号，触发 Swow Worker 的时间轮热重载。
        // Worker 每秒比对 max(version)，发现变化即 reloadSecondLevelTasks()，
        // 新增/修改/删除任务无需重启 worker 即可生效。
        // 用 DB 直接更新避免再次触发模型事件造成递归。
        try {
            $model->getConnection()
                ->table($model->getTable())
                ->where('id', $model->getKey())
                ->increment('version');
        } catch (\Throwable $e) {
            // 字段不存在或库不支持不影响主流程
        }
    }

    /**
     * 删除前处理
     */
    public function deleting($model): void
    {
        // 检查是否有正在执行的任务
        $runningCount = DB::table('task_schedule_log')
            ->where('task_id', $model->id)
            ->where('status', TaskStatus::RUNNING->value)
            ->count();

        if ($runningCount > 0) {
            admin_abort("该任务有 {$runningCount} 个正在执行的实例，无法删除");
        }
    }

    /**
     * 验证 Cron 表达式（支持 5 字段和 6 字段）
     */
    private function validateCronExpression(string $expression): bool
    {
        $parts = explode(' ', trim($expression));

        // 5 字段标准格式
        if (count($parts) === 5) {
            return CronExpression::isValidExpression($expression);
        }

        // 6 字段格式（含秒）
        if (count($parts) === 6) {
            $seconds = $parts[0];
            $cronPart = implode(' ', array_slice($parts, 1));

            if (!$this->isValidSecondField($seconds)) {
                return false;
            }

            return CronExpression::isValidExpression($cronPart);
        }

        return false;
    }

    /**
     * 验证秒字段
     */
    private function isValidSecondField(string $field): bool
    {
        if (!preg_match('/^[\d,\-\*\/]+$/', $field)) {
            return false;
        }

        $parts = explode(',', $field);
        foreach ($parts as $part) {
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                if (!is_numeric($step) || (int)$step < 1) return false;
                $part = $range;
            }

            if ($part === '*') continue;

            if (str_contains($part, '-')) {
                [$start, $end] = explode('-', $part, 2);
                if (!is_numeric($start) || !is_numeric($end)) return false;
                if ((int)$start < 0 || (int)$start > 59 || (int)$end < 0 || (int)$end > 59) return false;
            } elseif (is_numeric($part)) {
                $val = (int)$part;
                if ($val < 0 || $val > 59) return false;
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * 立即执行
     */
    public function execute(int $id): bool
    {
        $task = $this->getModel()->find($id);
        admin_abort_if(!$task, '此项任务不存在');

        // 防重叠检查
        if ($task->without_overlapping) {
            $isRunning = DB::table('task_schedule_log')
                ->where('task_id', $task->id)
                ->where('status', TaskStatus::RUNNING->value)
                ->exists();

            if ($isRunning) {
                admin_abort('任务正在执行中，请稍后再试');
            }
        }

        // 后台执行
        if ($task->in_background) {
            return $this->executeInBackground($task);
        }

        // 前台执行
        return $this->executeForeground($task);
    }

    /**
     * 前台执行
     */
    private function executeForeground(TaskSchedule $task): bool
    {
        $command = trim($task->command);
        $command = preg_replace('/^php\s+artisan\s+/i', '', $command);
        $command = preg_replace('/^artisan\s+/i', '', $command);

        $params = [];
        if (!empty($task->parameters)) {
            $params = is_array($task->parameters)
                ? $task->parameters
                : preg_split('/\s+/', trim($task->parameters));
        }

        try {
            $exitCode = Artisan::call($command, $params);

            // 记录日志
            DB::table('task_schedule_log')->insert([
                'task_id' => $task->id,
                'task_name' => $task->task_name,
                'command' => $task->command,
                'description' => $task->description,
                'status' => $exitCode === 0 ? TaskStatus::SUCCESS->value : TaskStatus::FAILED->value,
                'exit_code' => $exitCode,
                'output' => json_encode(['output' => Artisan::output()]),
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $exitCode === 0;
        } catch (\Throwable $e) {
            Log::error("Task execution failed [ID:{$task->id}]: " . $e->getMessage());

            DB::table('task_schedule_log')->insert([
                'task_id' => $task->id,
                'task_name' => $task->task_name,
                'command' => $task->command,
                'description' => $task->description,
                'status' => TaskStatus::FAILED->value,
                'exit_code' => 1,
                'output' => json_encode(['error' => $e->getMessage()]),
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * 后台执行
     */
    private function executeInBackground(TaskSchedule $task): bool
    {
        $command = sprintf(
            '%s artisan %s',
            PHP_BINARY,
            escapeshellarg(trim($task->command))
        );

        if (!empty($task->parameters)) {
            $params = is_array($task->parameters)
                ? $task->parameters
                : explode(' ', $task->parameters);
            foreach ($params as $param) {
                $command .= ' ' . escapeshellarg($param);
            }
        }

        $logPath = config('schedule.output.path') . '/';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        $command .= ' >> ' . $logPath . 'task_' . $task->id . '.log 2>&1 &';

        exec($command);

        return true;
    }

    /**
     * 暂停任务
     */
    public function pause(int $id): bool
    {
        return $this->getModel()->where('id', $id)->update(['active' => false]);
    }

    /**
     * 恢复任务
     */
    public function resume(int $id): bool
    {
        return $this->getModel()->where('id', $id)->update([
            'active' => true,
            'next_run_at' => now(),
        ]);
    }

    /**
     * 批量暂停
     */
    public function batchPause(array $ids): int
    {
        return $this->getModel()->whereIn('id', $ids)->update(['active' => false]);
    }

    /**
     * 批量恢复
     */
    public function batchResume(array $ids): int
    {
        return $this->getModel()->whereIn('id', $ids)->update([
            'active' => true,
            'next_run_at' => now(),
        ]);
    }

    /**
     * 获取任务统计
     */
    public function getStatistics(): array
    {
        $table = config('schedule.table', 'task_schedule');
        $logTable = config('schedule.log', 'task_schedule_log');

        return [
            'total_tasks' => DB::table($table)->count(),
            'active_tasks' => DB::table($table)->where('active', true)->count(),
            'inactive_tasks' => DB::table($table)->where('active', false)->count(),
            'total_executions' => DB::table($logTable)->count(),
            'success_count' => DB::table($logTable)->where('status', TaskStatus::SUCCESS->value)->count(),
            'failed_count' => DB::table($logTable)->where('status', TaskStatus::FAILED->value)->count(),
            'running_count' => DB::table($logTable)->where('status', TaskStatus::RUNNING->value)->count(),
            'avg_duration' => DB::table($logTable)->avg('duration') ?? 0,
            'max_duration' => DB::table($logTable)->max('duration') ?? 0,
            'by_precision' => $this->getCountByPrecision(),
            'by_group' => $this->getCountByGroup(),
            'recent_failures' => $this->getRecentFailures(),
        ];
    }

    /**
     * 按精度统计
     */
    private function getCountByPrecision(): array
    {
        return DB::table(config('schedule.table', 'task_schedule'))
            ->select('precision', DB::raw('count(*) as count'))
            ->groupBy('precision')
            ->pluck('count', 'precision')
            ->toArray();
    }

    /**
     * 按分组统计
     */
    private function getCountByGroup(): array
    {
        return DB::table(config('schedule.table', 'task_schedule'))
            ->select('group_id', DB::raw('count(*) as count'))
            ->groupBy('group_id')
            ->pluck('count', 'group_id')
            ->toArray();
    }

    /**
     * 最近失败记录
     */
    private function getRecentFailures(): array
    {
        return DB::table(config('schedule.log', 'task_schedule_log'))
            ->where('status', TaskStatus::FAILED->value)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * 预览下次执行时间
     */
    public function previewNextRuns(int $id, int $count = 5): array
    {
        $task = $this->getModel()->find($id);
        if (!$task) {
            return [];
        }

        $runs = [];
        $current = now();

        for ($i = 0; $i < $count; $i++) {
            $next = $task->calculateNextRun($current);
            if ($next) {
                $runs[] = $next->toIso8601String();
                $current = $next;
            }
        }

        return $runs;
    }
}
