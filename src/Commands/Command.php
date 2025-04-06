<?php
namespace DagaSmart\TaskSchedule\Commands;

use Illuminate\Console\Command as IlluminateCommand;
use Illuminate\Foundation\Bus\PendingClosureDispatch;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

abstract class Command extends IlluminateCommand
{
    public function __construct()
    {
        $scheduleEnum = Config::get('schedule.enum');

        $this->description = $scheduleEnum::fromValue($this->name)->description;

        parent::__construct();

        $this->defineGlobalOptions();
    }

    protected function defineGlobalOptions(): void
    {
        foreach ($this->getGlobalOptions() as $options) {
            $this->addOption(...array_values($options));
        }
    }

    protected function getGlobalOptions(): array
    {
        // array($name, $shortcut, $mode, $description, $defaultValue)
        return [
            ['queue', '-Q', InputOption::VALUE_OPTIONAL, 'Set the desired queue for the job', null],
            ['connection', '-C', InputOption::VALUE_OPTIONAL, 'Set the desired connection for the job.', null],
        ];
    }

    /**
     * 调度 Job
     * @param mixed $job
     * @return PendingClosureDispatch|PendingDispatch
     */
    protected function dispatch(mixed $job): PendingClosureDispatch|PendingDispatch
    {
        $pendingDispatch = dispatch($job);

        if ($queue = $this->option('queue')) {
            $pendingDispatch->onQueue($queue);
        }

        if ($connection = $this->option('connection')) {
            $pendingDispatch->onConnection($connection);
        }

        return $pendingDispatch;
    }

    /**
     * 执行 shell 命令.
     *
     * @param  string  $command
     * @return int|null
     */
    protected function exec(string $command): ?int
    {
        return Process::fromShellCommandline($command)
            ->setTty($this->isTtySupported())
            ->setWorkingDirectory(base_path())
            ->setTimeout(null)
            ->setIdleTimeout(null)
            ->mustRun(function ($type, $buffer) {
                $this->output->write($buffer);
            })
            ->getExitCode();
    }

    protected function isTtySupported(): bool
    {
        return config('app.env') !== 'testing' && Process::isTtySupported();
    }
}
