<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Swoole\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SwooleQueueWorkerStart extends Command
{
    protected $signature = 'swoole:queue start {--d|daemon : Run as a daemon}';
    protected $description = 'Start the Swoole Queue Worker.';

    public function handle()
    {
        $config = config('swoole.queue_worker');
        $workerNum = $config['worker_num'];
        $queueConnection = env('QUEUE_CONNECTION', 'default');
        $logFile = $config['log_file'];
        $pidFile = $config['pid_file'];

        if ($this->option('daemon')) {
            $this->info("Swoole Queue Worker starting in daemon mode...");
            $command = "php artisan queue:work {$queueConnection} --daemon --tries={$config['max_requests']} --sleep={$config['sleep']} --timeout={$config['timeout']} --queue=default > /dev/null 2>&1 & echo $! > {$pidFile}";
            exec($command);
        } else {
            $this->info("Swoole Queue Worker starting with {$workerNum} processes...");
            $processes = [];
            for ($i = 0; $i < $workerNum; $i++) {
                $process = new Process(function (Process $worker) use ($queueConnection, $config) {
                    Artisan::call("queue:work {$queueConnection} --tries={$config['max_requests']} --sleep={$config['sleep']} --timeout={$config['timeout']} --queue=default");
                }, false);

                $pid = $process->start();
                $processes[] = $pid;
                Log::info("Swoole Queue Worker started, PID: {$pid}");
            }

            file_put_contents($pidFile, getmypid());

            Process::wait(true);
            $this->info("All Swoole Queue Workers stopped.");
        }
    }
}
