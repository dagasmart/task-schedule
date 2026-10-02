<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = null;

    private string $name = 'task_schedule_group';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->name)) {
            return;
        }

        Schema::create($this->name, function (Blueprint $table) {
            $table->comment('任务分组表');
            $table->id();
            $table->string('group_name', 64)->comment('分组名称');
            $table->text('description')->nullable()->comment('分组描述');
            $table->smallInteger('sort')->default(0)->comment('排序[0-32767]');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('上级分组ID');
            $table->string('module', 64)->nullable()->comment('模块标识');
            $table->boolean('active')->default(true)->comment('是否激活');
            $table->timestamps();
            $table->softDeletes();

            // ✅ 显式命名索引，PG 全局不冲突
            $table->index('id', 'tsg_id_index');
            $table->index('parent_id', 'tsg_parent_id_index');
            $table->index('sort', 'tsg_sort_index');
            $table->index('active', 'tsg_active_index');
            $table->index('module', 'tsg_module_index');

            $table->unique(['group_name', 'parent_id'], 'uk_task_group_name_parent');
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->name)) {
            return;
        }

        Schema::dropIfExists($this->name);
    }
};
