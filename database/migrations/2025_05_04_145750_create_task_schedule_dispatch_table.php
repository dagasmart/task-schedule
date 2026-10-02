<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = null;

    private string $name = 'task_schedule_dispatch';

    /**
     * Run the migrations.
     *
     * 任务分发记录表 - 用于：
     * - 分布式锁追踪
     * - 单服务器执行保证
     * - 执行历史追踪
     */
    public function up(): void
    {
        if (Schema::hasTable($this->name)) {
            return;
        }

        Schema::create($this->name, function (Blueprint $table) {
            $table->comment('任务分发记录表');
            $table->id();
            $table->unsignedBigInteger('task_id')->comment('任务ID');
            $table->string('dispatch_id', 64)->comment('分发唯一ID(UUID)');
            $table->tinyInteger('status')->default(0)->comment('状态: 0待执行 1执行中 2成功 3失败/超时');
            $table->string('worker_id', 128)->nullable()->comment('Worker标识');
            $table->string('server_id', 128)->nullable()->comment('服务器标识');
            $table->bigInteger('lock_key')->nullable()->comment('PostgreSQL Advisory Lock Key');
            $table->timestamp('scheduled_at')->comment('计划执行时间');
            $table->timestamp('started_at')->nullable()->comment('实际开始时间');
            $table->timestamp('finished_at')->nullable()->comment('完成时间');
            $table->timestamps();

            // ✅ 显式命名索引，PG 全局不冲突
            $table->index('task_id', 'tsd_task_id_index');
            $table->index('dispatch_id', 'tsd_dispatch_id_index');
            $table->index('status', 'tsd_status_index');
            $table->index('worker_id', 'tsd_worker_id_index');
            $table->index('server_id', 'tsd_server_id_index');
            $table->index('scheduled_at', 'tsd_scheduled_at_index');
            $table->index(['status', 'started_at'], 'tsd_status_started_index');

            // 唯一约束
            $table->unique(['task_id', 'dispatch_id'], 'uk_task_schedule_dispatch_unique');
        });

        // ✅ PG HOT Update 优化
        if (DB::getDriverName() === 'pgsql') {
            // HOT Update
            DB::statement("ALTER TABLE {$this->name} SET (fillfactor = 90)");
            // 序列起始值
            DB::statement("ALTER SEQUENCE {$this->name}_id_seq RESTART WITH 1000000");
            // 生成过程函数
            $this->createCleanupFunction();
        }

        if (DB::getDriverName() === 'mysql') {
            // 序列起始值
            DB::statement("ALTER TABLE {$this->name} AUTO_INCREMENT = 1000000");
        }
    }

    /**
     * 创建清理过期分发记录的函数（PG 专用）
     * 函数名带表前缀，避免命名冲突
     */
    private function createCleanupFunction(): void
    {
        $fnName = 'cleanup_stale_task_dispatches';

        DB::statement("
            CREATE OR REPLACE FUNCTION {$fnName}()
            RETURNS INTEGER AS $$
            DECLARE
                v_updated INTEGER;
            BEGIN
                UPDATE {$this->name}
                SET status = 3,
                    finished_at = NOW()
                WHERE status IN (0, 1)
                  AND started_at < NOW() - INTERVAL '1 hour';

                GET DIAGNOSTICS v_updated = ROW_COUNT;
                RETURN v_updated;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->name)) {
            return;
        }

        // ✅ PG: 先删函数再删表
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS cleanup_stale_task_dispatches() CASCADE');
        }

        Schema::dropIfExists($this->name);
    }
};
