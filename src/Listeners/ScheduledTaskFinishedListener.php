<?php
namespace DagaSmart\TaskSchedule\Listeners;

use Illuminate\Console\Events\ScheduledTaskFinished;

class ScheduledTaskFinishedListener
{
    public function handle(ScheduledTaskFinished $event)
    {
    }
}
