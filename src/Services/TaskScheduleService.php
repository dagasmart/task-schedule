<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Services;

use Carbon\Carbon;
use Cron\CronExpression;
use DagaSmart\BizAdmin\Admin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use DagaSmart\TaskSchedule\Models\TaskSchedule;
use DagaSmart\TaskSchedule\Enums\PrecisionLevel;
use DagaSmart\TaskSchedule\Enums\TaskState;
use DagaSmart\TaskSchedule\Models\TaskScheduleRun;
use DagaSmart\TaskSchedule\Models\TaskScheduleDispatch;

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
        if (!method_exists($this->getModel(), 'getGroups')) {
            return [];
        }

        $data = $this->getModel()->getGroups();

        if (!$data instanceof \Illuminate\Support\Collection) {
            return [];
        }

        return array2tree($data->toArray());
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
    public function stateOption(): array
    {
        return $this->getModel()->stateOption();
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
            $precision = $data['precision'] ?? null;
            if (!$this->validateCronExpression($data['expression'], $precision)) {
                admin_abort('执行时间不是合法的 Cron 表达式');
            }
        }

        // 参数处理（保留 explode 兜底）
        if (isset($data['parameters']) && !empty($data['parameters'])) {
            if (is_string($data['parameters'])) {
                $decoded = json_decode($data['parameters'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $parts = explode(' ', trim($data['parameters']));
                    $data['parameters'] = array_values(array_filter($parts, function ($v) {
                        return $v !== '';
                    }));
                } else {
                    if (!is_array($decoded)) {
                        admin_abort('执行参数必须是 JSON 数组格式，如: ["--force", "--verbose"]');
                    }
                    $data['parameters'] = $decoded;
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

        // ★★★ 计算下次执行时间 — 修复秒级精度 ★★★
        if (!empty($data['expression']) && !empty($data['active'])) {
            $precision = $data['precision'] ?? null;

            if ($precision == PrecisionLevel::SECOND->value) {
                // ✅ 秒级：从 expression 提取间隔，算 next_run_at
                $interval = $this->parseIntervalFromExpression($data['expression']);

                if ($interval > 0) {
                    // 自动回填 interval_seconds
                    $data['interval_seconds'] = $interval;

                    // 对齐到下一个 interval 边界
                    $now = Carbon::now();
                    $timestamp = $now->timestamp;
                    $nextTimestamp = ceil($timestamp / $interval) * $interval;
                    $data['next_run_at'] = Carbon::createFromTimestamp($nextTimestamp);
                } else {
                    // fallback
                    $data['next_run_at'] = Carbon::now()->addSecond();
                    $data['interval_seconds'] = 1;
                }
            } else {
                // ✅ 非秒级：走原有 CronExpression 逻辑
                $task = $this->getModel();
                foreach ($data as $key => $value) {
                    $task->setAttribute($key, $value);
                }
                $nextRun = $task->calculateNextRun();
                $data['next_run_at'] = $nextRun;
            }
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

        // ✅ FIX: 同时清除预览缓存，避免旧数据残留
        cache()->forget("task_preview_{$model->id}_5");
        cache()->forget("task_preview_{$model->id}_10");
    }

    /**
     * 删除前处理
     */
    public function deleting($ids)
    {
        admin_abort_if(empty($ids), "任务id不能为空");

        if (!is_array($ids)) {
            $ids = implode(',', $ids);
        }

        $runningCount = TaskScheduleRun::query()
            ->whereIn('task_id', $ids)
            ->where('state', TaskState::RUNNING->value)
            ->where('created_at', '>', now()->subHours(24))
            ->whereNull('finished_at')  // 没结束的才是真正在跑
            ->count();

        if ($runningCount > 0) {
            admin_abort("该任务有 {$runningCount} 个正在执行的实例（24小时内启动且未完成），无法删除");
        }

        // 清理关联数据（防止外键报错）
        TaskScheduleRun::query()
            ->whereIn('task_id', $ids)
            ->delete();

        TaskScheduleDispatch::query()
            ->whereIn('task_id', $ids)
            ->delete();

        return $ids;  // ← 必须返回 $ids，框架后面要用
    }

    /**
     * ★★★ 从 expression 提取秒级间隔 ★★★
     */
    private function parseIntervalFromExpression(?string $expression): int
    {
        if (empty($expression)) {
            return 0;
        }

        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) === 6) {
            $secondField = $parts[0];

            // ✅ * = 每秒
            if ($secondField === '*') {
                return 1;
            }

            // */N
            if (preg_match('/^\*\/(\d+)$/', $secondField, $m)) {
                return (int) $m[1];
            }

            // 固定秒数
            if (is_numeric($secondField) && (int)$secondField >= 0 && (int)$secondField <= 59) {
                return 60;
            }

            // 范围
            if (preg_match('/^(\d+)-(\d+)$/', $secondField, $m)) {
                return 1;
            }

            // 逗号列表
            if (str_contains($secondField, ',')) {
                $values = array_filter(explode(',', $secondField), 'is_numeric');
                sort($values);
                $minDiff = 60;
                for ($i = 1; $i < count($values); $i++) {
                    $diff = (int)$values[$i] - (int)$values[$i - 1];
                    if ($diff > 0 && $diff < $minDiff) {
                        $minDiff = $diff;
                    }
                }
                return $minDiff < 60 ? $minDiff : 60;
            }
        }

        return 0;
    }

    /**
     * 验证 Cron 表达式（支持 5 字段和 6 字段）
     */
    private function validateCronExpression(string $expression, $precision = null): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        $partCount = count($parts);

        if ($precision !== null) {
            $expected = $precision == PrecisionLevel::SECOND->value ? 6 : 5;
            if ($partCount !== $expected) {
                $msg = $precision === PrecisionLevel::SECOND->value
                    ? '必需是 6段(秒 分 时 日 月 周) Cron表达式'
                    : '必需是 5段(分 时 日 月 周) Cron表达式';
                admin_abort($msg);
            }
        }

        if ($partCount === 5) {
            return CronExpression::isValidExpression($expression);
        }

        if ($partCount === 6) {
            $seconds = $parts[0];
            $cronPart = implode(' ', array_slice($parts, 1));
            return $this->isValidSecondField($seconds) && CronExpression::isValidExpression($cronPart);
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
                ->where('state', TaskState::RUNNING->value)
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
                'state' => $exitCode === 0 ? TaskState::SUCCESS->value : TaskState::FAILED->value,
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
                'state' => TaskState::FAILED->value,
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
            'success_count' => DB::table($logTable)->where('state', TaskState::SUCCESS->value)->count(),
            'failed_count' => DB::table($logTable)->where('state', TaskState::FAILED->value)->count(),
            'running_count' => DB::table($logTable)->where('state', TaskState::RUNNING->value)->count(),
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
        return TaskSchedule::query()
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
        return TaskSchedule::query()
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
        return TaskScheduleRun::query()
            ->where('state', TaskState::FAILED->value)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * 预览下次执行时间
     */
    public function previewRuns(int $id, int $count = 5): array
    {
        // ✅ FIX: 加缓存，防止 silentPolling 5~6秒轮询打爆 PG 连接池
        $cacheKey = "task_preview_{$id}_{$count}";
        $cacheTtl = 30; // 30秒缓存

        return cache()->remember($cacheKey, $cacheTtl, function () use ($id, $count) {
            return $this->buildPreviewData($id, $count);
        });
    }

    /**
     * 构建预览数据（内部分离，便于缓存）
     */
    private function buildPreviewData(int $id, int $count): array
    {
        $task = $this->getModel()->find($id);
        if (!$task) {
            return ['next_runs' => [], 'last_runs' => [], 'pie_data' => [], 'bar_categories' => [], 'bar_data' => [], 'trend_runs' => [], 'trend_months' => [], 'trend_success' => [], 'trend_failed' => []];
        }

        // ===== next_runs =====
        $next_runs = [];
        $interval = $this->parseIntervalFromExpression($task->expression);

        if ($interval > 0) {
            $timestamp = Carbon::now()->timestamp;
            $nextTimestamp = ceil($timestamp / $interval) * $interval;
            $next = now()->setTimestamp($nextTimestamp);

            for ($i = 0; $i < $count; $i++) {
                $next_runs[] = [
                    'id'       => $i + 1,
                    'run_time' => $next->toDateTimeString(),
                ];
                $next = $next->copy()->addSeconds($interval);
            }
        } else {
            $current = Carbon::now()->toMutable();
            $maxAttempts = $count * 3;
            $attempts = 0;

            while (count($next_runs) < $count && $attempts < $maxAttempts) {
                $attempts++;
                $next = $task->calculateNextRun($current);
                if (!$next) {
                    break;
                }

                $next_runs[] = [
                    'id'       => count($next_runs) + 1,
                    'run_time' => $next->toDateTimeString(),
                ];
                $current = $next;
            }
        }

        // ===== last_runs =====
        $last_runs = TaskScheduleRun::query()
            ->select('id', 'started_at', 'finished_at', 'duration', 'state')
            ->where('task_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit($count)
            ->get()
            ->toArray();

        // ✅ FIX: state 转 string，让 amis mapping/statusMap 能正确匹配（int 2 ≠ string '2'）
        foreach ($last_runs as &$run) {
            $run['state'] = (string)$run['state'];
        }
        unset($run);

        // ===== stat_runs：按状态聚合 =====
        $stateMap = [1 => '待执行', 2 => '成功', 3 => '失败'];

        // ✅ FIX: CASE WHEN 加 ELSE 0，PG 里 SUM(NULL) 会返回 NULL
        $statRows = TaskScheduleRun::query()
            ->select(
                'state',
                DB::raw('COUNT(*) as count'),
                DB::raw("SUM(CASE WHEN created_at > CURRENT_TIMESTAMP - INTERVAL '7 day' THEN 1 ELSE 0 END) AS recent_7d_count"),
                DB::raw("SUM(CASE WHEN created_at > CURRENT_TIMESTAMP - INTERVAL '1 month' THEN 1 ELSE 0 END) AS recent_1m_count"),
                DB::raw("SUM(CASE WHEN created_at > CURRENT_TIMESTAMP - INTERVAL '1 year' THEN 1 ELSE 0 END) AS recent_1y_count"),
            )
            ->where('task_id', $id)
            ->whereIn('state', [TaskState::SUCCESS->value, TaskState::FAILED->value])
            ->groupBy('state')
            ->orderBy('state')
            ->get()
            ->map(function ($row) use ($stateMap) {
                $state = $row->state;
                $countVal = (int) $row->count;
                $recent7d = (int) ($row->recent_7d_count ?? 0);
                $recent1m = (int) ($row->recent_1m_count ?? 0);
                $recent1y = (int) ($row->recent_1y_count ?? 0);

                return [
                    'state'              => (string)$state, // ✅ FIX: 也转成 string
                    'state_label'        => $stateMap[$state] ?? '未知',
                    'count'              => $countVal,
                    'recent_7d_count'   => $recent7d,
                    'recent_1m_count'   => $recent1m,
                    'recent_1y_count'   => $recent1y,
                ];
            })
            ->values()
            ->toArray();

        // 过滤掉 count=0 的项
        $pieItems = array_values(array_filter($statRows, fn($r) => $r['count'] > 0));
        $barItems = array_values(array_filter($statRows, fn($r) => $r['count'] > 0));

        // 最近7日趋势（已缓存）
        $daily_runs = $this->getRecent7DaysTrend($id);

        // ===== trend_runs：月度趋势（已缓存）=====
        $trend_runs = $this->getMonthlyTrend($id);

        // ===== 最终返回（格式化图表数据，前端零转换） =====
        return [
            'next_runs' => $next_runs,
            'last_runs' => $last_runs,

            // ✅ 饼图：直接格式化为 ECharts pie series data
            'pie_data' => array_map(function ($item) {
                return [
                    'name'  => $item['state_label'],
                    'value' => $item['count'],
                ];
            }, $pieItems),

            // ✅ 柱图：categories + data 分离到根级
            'bar_categories' => array_values(array_map(fn($item) => $item['state_label'], $barItems)),
            'bar_data'       => array_values(array_map(fn($item) => $item['count'], $barItems)),

            // ✅ 最近7日趋势, 柱形图
            'daily_runs' => $daily_runs,
            // ✅ amis 图表直接读根级字段，避免模板复杂处理
            'daily_dates'   => $daily_runs['dates'],
            'daily_success' => $daily_runs['success'],
            'daily_failed'  => $daily_runs['failed'],

            // ✅ 最近12月趋势, 折线图
            'trend_runs'     => $trend_runs,
            // ✅ 折线图：根级平铺字段
            'trend_months'   => $trend_runs['months'],
            'trend_success'  => $trend_runs['success'],
            'trend_failed'   => $trend_runs['failed'],
        ];
    }

    /**
     * 获取最近7日成功/失败趋势（PostgreSQL / MySQL 兼容）
     * ✅ FIX: 加缓存，防止轮询时重复查询
     *
     * 返回：
     * [
     *   'dates'   => ['10-29', '10-30', ..., '11-04'],
     *   'success' => [0,0,...],
     *   'failed'  => [0,0,...],
     * ]
     */
    private function getRecent7DaysTrend(int $taskId): array
    {
        $cacheKey = "task_trend_7d_{$taskId}";
        $cacheTtl = 60; // 60秒缓存

        return cache()->remember($cacheKey, $cacheTtl, function () use ($taskId) {
            return $this->build7DaysTrend($taskId);
        });
    }

    /**
     * 构建7日趋势数据
     */
    private function build7DaysTrend(int $taskId): array
    {
        $dates   = [];
        $keys    = []; // Y-m-d，用于数据库结果精确匹配
        $success = [];
        $failed  = [];

        $now = now();

        // ✅ 生成最近7天：今天往前推6天 ~ 今天
        for ($i = 6; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i);

            $keys[]  = $date->format('Y-m-d');
            // 显示用：m-d，如 10-29；跨月自然能区分
            $dates[] = $date->format('m-d');

            $success[] = 0;
            $failed[]  = 0;
        }

        // ✅ 数据库方言适配：按日期分组
        $dateFormat = DB::getDriverName() === 'pgsql'
            ? "TO_CHAR(created_at, 'YYYY-MM-DD')"
            : "DATE_FORMAT(created_at, '%Y-%m-%d')";

        $rows = TaskScheduleRun::query()
            ->selectRaw("{$dateFormat} as day")
            ->selectRaw('state')
            ->selectRaw('COUNT(*) as cnt')
            ->where('task_id', $taskId)
            // ✅ 最近7日：从 6天前 00:00:00 开始，到今天结束
            ->where('created_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->where('created_at', '<=', $now->copy()->endOfDay())
            ->groupBy(DB::raw($dateFormat), 'state')
            ->orderBy(DB::raw($dateFormat))
            ->get();

        foreach ($rows as $row) {
            // ✅ 用 Y-m-d 精确匹配，避免任何格式/跨月歧义
            $idx = array_search($row->day, $keys, true);

            if ($idx !== false) {
                if ($row->state == TaskState::SUCCESS->value) {
                    $success[$idx] = (int) $row->cnt;
                } elseif ($row->state == TaskState::FAILED->value) {
                    $failed[$idx] = (int) $row->cnt;
                }
            }
        }

        return [
            'dates'   => $dates,
            'success' => $success,
            'failed'  => $failed,
        ];
    }

    /**
     * 获取近12个月成功/失败趋势（兼容 PostgreSQL / MySQL）
     * ✅ FIX: 加缓存，防止轮询时重复查询
     * 跨年时仅对 1 月补年份显示
     */
    private function getMonthlyTrend(int $taskId): array
    {
        $cacheKey = "task_trend_monthly_{$taskId}";
        $cacheTtl = 300; // 5分钟缓存（月度数据变化频率低）

        return cache()->remember($cacheKey, $cacheTtl, function () use ($taskId) {
            return $this->buildMonthlyTrend($taskId);
        });
    }

    /**
     * 构建月度趋势数据
     */
    private function buildMonthlyTrend(int $taskId): array
    {
        $months    = [];   // x轴显示标签
        $monthKeys = [];   // 数据库匹配用 YYYY-MM
        $success   = [];
        $failed    = [];

        $now = now();

        // ✅ 判断是否跨年（近12个月里是否包含1月）
        $crossYear = false;
        for ($i = 11; $i >= 0; $i--) {
            if (now()->subMonths($i)->month === 1) {
                $crossYear = true;
                break;
            }
        }

        for ($i = 11; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i);
            $y    = $date->year;
            $m    = $date->month;

            // 匹配 key（始终 YYYY-MM）
            $monthKeys[] = $date->format('Y-m');

            // ✅ 显示标签：跨年且是1月时补年份
            if ($crossYear && $m === 1) {
                $months[] = $y . '年' . $m . '月';   // 如：2026年1月
            } else {
                $months[] = $m . '月';                // 如：2月、3月…
            }

            $success[] = 0;
            $failed[]  = 0;
        }

        // ✅ 数据库方言适配
        $dateFormat = DB::getDriverName() === 'pgsql'
            ? "TO_CHAR(created_at, 'YYYY-MM')"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $rows = TaskScheduleRun::query()
            ->selectRaw("{$dateFormat} as month")
            ->selectRaw('state')
            ->selectRaw('COUNT(*) as cnt')
            ->where('task_id', $taskId)
            ->where('created_at', '>=', $now->copy()->subMonths(12)->startOfMonth())
            ->groupBy(DB::raw($dateFormat), 'state')
            ->orderBy(DB::raw($dateFormat))
            ->get();

        foreach ($rows as $row) {
            // ✅ 用 YYYY-MM 精确匹配，彻底避免跨年混淆
            $idx = array_search($row->month, $monthKeys, true);

            if ($idx !== false) {
                if ($row->state == TaskState::SUCCESS->value) {
                    $success[$idx] = (int) $row->cnt;
                } elseif ($row->state == TaskState::FAILED->value) {
                    $failed[$idx] = (int) $row->cnt;
                }
            }
        }

        return [
            'months'  => $months,    // ['11月','12月','2026年1月','2月',…,'10月']
            'success' => $success,
            'failed'  => $failed,
        ];
    }
}
