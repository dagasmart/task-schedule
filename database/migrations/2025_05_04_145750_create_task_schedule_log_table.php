<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = null;

    private string $name = 'task_schedule_log';

    /**
     * Run the migrations.
     *
     * PostgreSQL 优化：
     * - 使用 JSONB 存储输出信息（GIN 索引支持）
     * - 部分索引加速失败/慢查询
     * - HOT Update 优化
     */
    public function up(): void
    {
        if (Schema::hasTable($this->name)) {
            return;
        }

        Schema::create($this->name, function (Blueprint $table) {
            $table->comment('任务调度日志表');
            $table->id();
            $table->unsignedBigInteger('task_id')->comment('任务ID');
            $table->string('task_name', 128)->nullable()->comment('任务名称');
            $table->text('command')->nullable()->comment('执行命令');
            $table->text('description')->nullable()->comment('执行描述');

            // ✅ 统一用 state 字段：true=成功, false=失败
            // 如果后续需要"执行中"等中间状态，改为 tinyInteger
            $table->boolean('state')->default(false)->comment('执行状态: true成功 false失败');

            $table->jsonb('result')->nullable()->comment('执行结果(JSONB)');
            $table->jsonb('output')->nullable()->comment('输出信息(JSONB)');
            $table->smallInteger('exit_code')->nullable()->comment('退出码');
            $table->decimal('duration', 12, 4)->nullable()->comment('耗时(秒)');
            $table->unsignedBigInteger('memory_peak')->nullable()->comment('内存峰值(bytes)');
            $table->integer('pid')->nullable()->comment('进程ID');
            $table->string('worker_id', 64)->nullable()->comment('Worker标识');
            $table->string('module', 64)->nullable()->comment('模块');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('finished_at')->nullable()->comment('结束时间');
            $table->timestamps();

            // ✅ 显式命名索引，PG 全局不冲突
            $table->index('task_id', 'tsl_task_id_index');
            $table->index('module', 'tsl_module_index');
            $table->index('state', 'tsl_state_index');
            $table->index('created_at', 'tsl_created_at_index');
            $table->index('duration', 'tsl_duration_index');
            $table->index(['task_id', 'state'], 'tsl_task_state_index');
            $table->index(['task_id', 'created_at'], 'tsl_task_created_index');
        });

        // ✅ PG 专用优化
        if (DB::getDriverName() === 'pgsql') {
            // HOT Update
            DB::statement("ALTER TABLE {$this->name} SET (fillfactor = 90)");
            // 序列起始值
            DB::statement("ALTER SEQUENCE {$this->name}_id_seq RESTART WITH 1000000");

            // JSONB GIN 索引（支持 output 内容搜索）
            DB::statement("
                CREATE INDEX IF NOT EXISTS {$this->name}_output_gin
                ON {$this->name} USING gin(output jsonb_path_ops)
            ");

            // 部分索引：失败任务（用 state=false 筛选）
            DB::statement("
                CREATE INDEX IF NOT EXISTS {$this->name}_failed_partial
                ON {$this->name} (task_id, created_at)
                WHERE state = false
            ");

            // 部分索引：慢任务（>30秒）
            DB::statement("
                CREATE INDEX IF NOT EXISTS {$this->name}_slow_partial
                ON {$this->name} (task_id, duration)
                WHERE duration > 30
            ");
        }

        if (DB::getDriverName() === 'mysql') {
            // 序列起始值
            DB::statement("ALTER TABLE {$this->name} AUTO_INCREMENT = 1000000");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->name)) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // 部分索引和 GIN 索引会随表 DROP 自动清理
            // 显式清理以防万一
            DB::statement("DROP INDEX IF EXISTS {$this->name}_output_gin CASCADE");
            DB::statement("DROP INDEX IF EXISTS {$this->name}_failed_partial CASCADE");
            DB::statement("DROP INDEX IF EXISTS {$this->name}_slow_partial CASCADE");
        }

        Schema::dropIfExists($this->name);
    }
};
