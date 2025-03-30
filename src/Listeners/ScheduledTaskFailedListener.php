<?php
namespace DagaSmart\TaskSchedule\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;

class ScheduledTaskFailedListener
{
    public function handle(ScheduledTaskFailed $event)
    {
    }
}
