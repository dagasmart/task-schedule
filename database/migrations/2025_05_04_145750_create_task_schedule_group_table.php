<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class CreateTaskScheduleGroupTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(Config::get('schedule.group', 'task_schedule_group'), function (Blueprint $table) {
            $table->comment('任务分组表');
            $table->id();
            $table->string('group_name')->comment('分组名称');
            $table->string('description')->nullable()->comment('分组描述');
            $table->integer('sort')->default(0)->comment('排序[0-255]');
            $table->string('parent_id')->nullable()->comment('上级分组');
            $table->timestamps();

            $table->index('id');
            $table->index('parent_id');
            $table->unique(['group_name', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(Config::get('schedule.group', 'task_schedule_group'));
    }
}
