<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;

class SimulatedClient extends Command
{
    protected $signature = 'stress:grab {ticket_id} {--users=100 : Number of simulated users} {--requests_per_user=1 : Number of requests per user} {--concurrency=50 : Max concurrent requests} {--target_url=http://nginx/api/ticket/grab : Target API endpoint} {--token= : API token for authentication}';
    protected $description = 'Simulate high-concurrency ticket grabbing requests and generate a report.';

    public function handle()
    {
        $ticketId = $this->argument('ticket_id');
        $users = (int)$this->option('users');
        $requestsPerUser = (int)$this->option('requests_per_user');
        $concurrency = (int)$this->option('concurrency');
        $targetUrl = rtrim($this->option('target_url'), '/') . "/{$ticketId}";
        $apiToken = $this->option('token') ?: env('TEST_API_TOKEN', '');

        if (!$apiToken) {
            $this->error('Please provide an API token (--token or set TEST_API_TOKEN environment variable).');
            return 1;
        }

        $client = new Client([
            'base_uri' => '', // 這裡設置為空，因為 Request 對象會帶上完整 URL
            'timeout' => 30, // 請求超時時間
            'connect_timeout' => 5, // 連接超時時間
            'headers' => [
                'Authorization' => 'Bearer ' . $apiToken,
                'Accept' => 'application/json',
            ],
        ]);

        $totalRequests = $users * $requestsPerUser;
        $this->info("Simulating {$users} users, {$requestsPerUser} requests per user, total {$totalRequests} requests. Max concurrency: {$concurrency}");

        $startTime = microtime(true);

        $successCount = 0;
        $failureCount = 0;
        $requests = [];

        for ($i = 1; $i <= $users; $i++) {
            for ($j = 1; $j <= $requestsPerUser; $j++) {
                $requests[] = new Request('POST', $targetUrl);
            }
        }

        $pool = new Pool($client, $requests, [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response) use (&$successCount) {
                if ($response->getStatusCode() === 201) {
                    $successCount++;
                } else {
                    // 可選：記錄非 201 的響應
                    // Log::warning('壓力測試請求響應非 201: ' . $response->getStatusCode() . ' ' . $response->getBody());
                }
            },
            'rejected' => function ($reason) use (&$failureCount) {
                $failureCount++;
                Log::error('壓力測試請求失敗: ' . $reason->getMessage());
            },
        ]);

        // 啟動請求池並等待所有請求完成
        $promise = $pool->promise();
        $promise->wait();

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $qps = ($duration > 0) ? ($totalRequests / $duration) : 0;

        $this->output->newLine();
        $this->output->writeln("================ 壓力測試報告 ================");
        $this->info("總請求數：{$totalRequests}");
        $this->info("成功請求數：{$successCount}");
        $this->info("失敗請求數：{$failureCount}");
        $this->info("總耗時： " . number_format($duration, 2) . " 秒");
        $this->info("每秒請求量 (QPS)：" . number_format($qps, 2));
        $this->info("成功率： " . number_format(($totalRequests > 0 ? ($successCount / $totalRequests * 100) : 0), 2) . "%");
        $this->output->writeln("============================================");

        Log::info("壓力測試報告：總請求 {$totalRequests}, 成功 {$successCount}, 失敗 {$failureCount}, QPS: {$qps}");

        return 0;
    }
}
