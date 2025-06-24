<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Swoole\Http\Server;
use Illuminate\Support\Facades\Log;

class SwooleHttpServerStart extends Command
{
    protected $signature = 'swoole:http start {--d|daemon : Run as a daemon}';
    protected $description = 'Start the Swoole HTTP server.';

    public function handle()
    {
        $config = config('swoole.http_server');
        $host = $config['host'];
        $port = $config['port'];
        $options = $config['options'];

        if ($this->option('daemon')) {
            $options['daemonize'] = true;
            $this->info("Swoole HTTP server starting in daemon mode...");
        } else {
            $this->info("Swoole HTTP server starting on {$host}:{$port}...");
        }

        $server = new Server($host, $port);

        $server->set($options);

        // 使用閉包捕獲 Laravel Application 實例
        $app = $this->getApplication()->getLaravel();

        $server->on('request', function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) use ($app) {
            $illuminateRequest = \Illuminate\Http\Request::create(
                $swooleRequest->server['request_uri'],
                $swooleRequest->getMethod(),
                $swooleRequest->get ?? [],
                $swooleRequest->cookie ?? [],
                $swooleRequest->files ?? [],
                $swooleRequest->server ?? [],
                $swooleRequest->rawContent()
            );

            // 處理請求並獲取響應
            $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
            $illuminateResponse = $kernel->handle($illuminateRequest);

            // 發送響應
            foreach ($illuminateResponse->headers->allPreserveCase() as $name => $values) {
                foreach ($values as $value) {
                    $swooleResponse->header($name, $value);
                }
            }
            $swooleResponse->status($illuminateResponse->getStatusCode());
            $swooleResponse->end($illuminateResponse->getContent());

            $kernel->terminate($illuminateRequest, $illuminateResponse);
        });

        $server->start();
    }
}
