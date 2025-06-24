<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle()
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::warning("Order {$this->orderId} not found during processing.");
            return;
        }

        if ($order->status !== Order::STATUS_PENDING) {
            Log::info("Order {$this->orderId} is not in PENDING status, skipping processing.");
            return;
        }

        Log::info("Processing order {$this->orderId} for user {$order->user_id}, ticket {$order->ticket_id}");

        $timeoutMinutes = $order->ticket->timeout_minutes ?? 15;

        // 派發支付監控 Job，延遲到支付超時時間後執行
        Bus::dispatch(new MonitorPaymentJob($this->orderId))
            ->afterCommit() // 確保只有 DB 事務提交後才派發 Job
            ->delay(now()->addMinutes($timeoutMinutes));
    }
}
