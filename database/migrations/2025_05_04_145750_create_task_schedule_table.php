<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class CreateTaskScheduleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(Config::get('schedule.table', 'task_schedule'), function (Blueprint $table) {
            $table->comment('任务调度表');
            $table->id();
            $table->string('task_name')->nullable()->comment('任务名称');
            $table->string('description')->nullable()->comment('任务描述');
            $table->string('command')->nullable()->comment('任务命令');
            $table->string('parameters')->nullable()->comment('执行参数');
            $table->string('expression')->nullable()->comment('执行时间间隔，cron 表达式');
            $table->boolean('active')->default(false)->comment('运行状态：0 开启，1 关闭');
            $table->string('timezone')->comment('时区');
            $table->json('environments')->nullable()->comment('环境设置');
            $table->unsignedInteger('without_overlapping')->default(0)->comment('是否重复执行');
            $table->boolean('on_one_server')->default(false)->comment('是否当前服务器');
            $table->boolean('in_background')->default(false)->comment('是否后台运行');
            $table->boolean('in_maintenance_mode')->default(false)->comment('是否维护模式');
            $table->string('output_file_path')->nullable()->comment('输出的文件路径');
            $table->boolean('output_append')->default(false)->comment('输出到文件时是否进行追加');
            $table->string('output_email')->nullable()->comment('输出发送邮件');
            $table->boolean('output_email_on_failure')->default(false)->comment('执行失败时发送输出到邮件');
            $table->integer('group_id')->default(0)->comment('分组id');
            $table->string('module')->nullable()->comment('模块');
            $table->string('oper_id')->default(0)->comment('发布人id');
            $table->string('oper_as')->nullable()->comment('发布人');
            $table->timestamps();

            $table->index('id');
            $table->index('active');
            $table->index('command');
            $table->unique(['task_name', 'group_id', 'module']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(Config::get('schedule.table', 'task_schedule'));
    }
}
