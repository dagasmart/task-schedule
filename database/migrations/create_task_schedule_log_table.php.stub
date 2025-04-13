<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class CreateTaskScheduleLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(Config::get('schedule.log', 'task_schedule_log'), function (Blueprint $table) {
            $table->comment('任务日志表');
            $table->id();
            $table->integer('task_id')->nullable()->comment('任务id');
            $table->string('description')->nullable()->comment('执行描述');
            $table->tinyInteger('state')->nullable()->comment('执行状态：1成功，2失败');
            $table->string('result')->nullable()->comment('执行结果');
            $table->string('module')->nullable()->comment('模块');
            $table->timestamps();

            $table->index('task_id');
            $table->index('module');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(Config::get('schedule.log', 'task_schedule_log'));
    }
}
