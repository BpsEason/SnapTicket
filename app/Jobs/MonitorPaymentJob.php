<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\TicketService;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class MonitorPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(TicketService $ticketService)
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::warning("Order {$this->orderId} not found during payment monitoring.");
            return;
        }

        if ($order->status !== Order::STATUS_PENDING) {
            Log::info("Order {$this->orderId} is not in PENDING status, no action taken.");
            return;
        }

        // 如果訂單仍然是 PENDING 狀態，則視為超時未支付，進行取消並恢復庫存
        $ticketService->restoreTicketStockForOrder($this->orderId);

        Log::info("Order {$this->orderId} payment timed out, stock restored and order cancelled.");
    }
}
