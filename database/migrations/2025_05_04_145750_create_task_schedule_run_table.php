<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任务执行记录表（调度器视角）。
 *
 * 与 task_schedule_log 的区别：
 *   - log 表偏业务结果；本表偏调度器原生数据；
 *   - 本表由监听器自动维护，业务无需关心；
 *   - 字段对齐 Laravel 13 的 ScheduledTaskFinished / ScheduledTaskFailed。
 *
 * 本表数据量大、增长快，生产环境应配合分区或定期归档。
 */
return new class extends Migration
{
    protected $connection = null;

    private string $name = 'task_schedule_run';

    public function up(): void
    {
        if (Schema::hasTable($this->name)) {
            return;
        }

        Schema::create($this->name, function (Blueprint $table) {
            $table->comment('任务执行记录表');
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->comment('关联 task_schedule.id');
            $table->string('event_name')->nullable()->comment('事件名称，即 Event::name()');
            $table->string('command')->nullable()->comment('命令快照');
            $table->string('expression', 100)->nullable()->comment('cron 表达式快照');
            $table->string('timezone', 64)->nullable()->comment('时区快照');

            $table->string('state', 16)->comment('状态：running/success/failed/skipped');
            $table->integer('exit_code')->nullable()->comment('退出码');
            $table->decimal('duration', 10, 3)->nullable()->comment('耗时(秒)');
            $table->text('output')->nullable()->comment('输出信息');
            $table->text('error_message')->nullable()->comment('错误信息');

            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('finished_at')->nullable()->comment('结束时间');

            $table->string('mutex_name')->nullable()->comment('互斥锁名称，用于排错');
            $table->boolean('skipped_because_overlapping')
                ->default(false)
                ->comment('是否因防重叠而跳过');

            $table->timestamps();

            $table->index('task_id');
            $table->index('event_name');
            $table->index('state');
            $table->index('started_at');
        });

        // ✅ PG HOT Update 优化
        if (DB::getDriverName() === 'pgsql') {
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

    public function down(): void
    {
        if (! Schema::hasTable($this->name)) {
            return;
        }

        Schema::dropIfExists($this->name);
    }
};
