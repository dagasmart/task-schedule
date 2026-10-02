<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = null;

    private string $name = 'task_schedule';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->name)) {
            return;
        }

        Schema::create($this->name, function (Blueprint $table) {
            $table->comment('任务调度表');
            $table->id()->comment('主键ID');

            // 基础信息
            $table->string('task_name', 128)->comment('任务名称');
            $table->string('task_type', 20)->default('command')->comment('任务类型: command/job/url/shell/closure');
            $table->text('description')->nullable()->comment('任务描述');
            $table->text('command')->comment('执行命令/类名/URL');
            $table->jsonb('parameters')->nullable()->comment('执行参数 (JSONB for PG)');
            $table->jsonb('options')->nullable()->comment('额外选项');

            // 调度精度
            $table->tinyInteger('precision')->default(2)->comment('精度级别: 1秒 2分 3时 4天 5周 6月');
            $table->string('expression', 100)->nullable()->comment('Cron表达式');
            $table->integer('interval_seconds')->default(60)->comment('秒级间隔(秒)，仅 precision=1 时生效');
            $table->integer('version')->default(0)->comment('版本号，供 Swow Worker 热更新检测');

            // 状态
            $table->boolean('active')->default(false)->comment('是否激活');
            $table->tinyInteger('priority')->default(0)->comment('优先级(0-100)');

            // 时间
            $table->string('timezone', 64)->default('Asia/Shanghai')->comment('时区');
            $table->timestamp('last_run_at')->nullable()->comment('上次执行时间');
            $table->timestamp('next_run_at')->nullable()->comment('下次执行时间');

            // 环境
            $table->jsonb('environments')->nullable()->comment('运行环境限制');

            // 防重叠
            $table->boolean('without_overlapping')->default(false)->comment('防重复执行');
            $table->integer('overlap_release_minutes')->default(1440)->comment('锁释放时间(分)');

            // 执行控制
            $table->boolean('on_one_server')->default(false)->comment('单服务器执行');
            $table->boolean('in_background')->default(false)->comment('后台运行');
            $table->boolean('in_maintenance_mode')->default(false)->comment('维护模式执行');

            // 并发控制
            $table->integer('concurrent_limit')->default(0)->comment('并发限制(0=无限制)');

            // 超时与重试
            $table->integer('max_runtime')->default(0)->comment('最大运行时间(秒,0=不限制)');
            $table->string('timeout_action', 20)->default('skip')->comment('超时动作: skip/retry/kill');
            $table->tinyInteger('retry_times')->default(0)->comment('重试次数');
            $table->integer('retry_interval')->default(60)->comment('重试间隔(秒)');

            // 输出
            $table->string('output_file_path')->nullable()->comment('输出文件路径');
            $table->boolean('output_append')->default(false)->comment('追加输出');
            $table->string('output_email', 200)->nullable()->comment('输出发送邮件');
            $table->boolean('output_email_on_failure')->default(false)->comment('失败时发送邮件');

            // 关联
            $table->integer('group_id')->default(0)->comment('分组ID');
            $table->integer('creator_id')->default(0)->comment('创建人ID');
            $table->string('creator', 64)->nullable()->comment('创建人');
            $table->string('module', 64)->nullable()->comment('模块标识');
            $table->unsignedBigInteger('mer_id')->nullable()->comment('商户ID');

            $table->timestamps();
            $table->softDeletes();

            // ✅ 显式命名索引
            $table->index('id', 'ts_id_index');
            $table->index('task_name', 'ts_task_name_index');
            $table->index('active', 'ts_active_index');
            $table->index('command', 'ts_command_index');
            $table->index('group_id', 'ts_group_id_index');
            $table->index('next_run_at', 'ts_next_run_at_index');
            $table->index('precision', 'ts_precision_index');
            $table->index('version', 'ts_version_index');

            // 唯一约束
            $table->unique(
                ['task_name', 'command', 'module', 'mer_id'],
                'uk_task_schedule_unique'
            );
        });

        // ✅ PG 部分索引
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_ts_active_priority
                ON task_schedule ("priority" DESC, "next_run_at" ASC)
                WHERE active = true
            ');
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_ts_precision_next
                ON task_schedule ("precision", "next_run_at" ASC)
                WHERE active = true
            ');
            // HOT Update
            DB::statement("ALTER TABLE {$this->name} SET (fillfactor = 90)");
            // 序列起始值
            DB::statement("ALTER SEQUENCE {$this->name}_id_seq RESTART WITH 1000000");
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

        // PG 部分索引会随表自动删除
        Schema::dropIfExists($this->name);
    }
};
