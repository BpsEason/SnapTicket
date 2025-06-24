<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Order;
use App\Jobs\ProcessOrderJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class TicketService
{
    /**
     * 初始化票務庫存到 Redis。
     * * @param int $ticketId
     * @param int $stock
     * @return void
     */
    public function initializeTicketStock(int $ticketId, int $stock): void
    {
        Redis::set("ticket:{$ticketId}:stock", $stock);
        Log::info("Initialized Redis stock for ticket {$ticketId} to {$stock}");
    }

    /**
     * 獲取票務實時庫存 (從 Redis)。
     * * @param int $ticketId
     * @return int
     */
    public function getTicketStock(int $ticketId): int
    {
        return (int) Redis::get("ticket:{$ticketId}:stock");
    }

    /**
     * 搶票邏輯 (包含 Redis Lua 腳本原子性扣減)。
     * * @param int $ticketId
     * @param int $userId
     * @return int 訂單 ID
     * @throws ValidationException
     * @throws \Exception
     */
    public function grabTicket(int $ticketId, int $userId): int
    {
        $ticket = Ticket::findOrFail($ticketId);

        // 檢查活動時間
        $now = Carbon::now();
        if ($now->lt($ticket->start_time)) {
            throw ValidationException::withMessages(['ticket' => '搶票活動尚未開始。']);
        }
        if ($now->gt($ticket->end_time)) {
            throw ValidationException::withMessages(['ticket' => '搶票活動已結束。']);
        }

        // 檢查用戶是否已搶過此票 (簡單的重複搶購限制)
        $userTicketLockKey = "user:ticket:lock:{$userId}:{$ticketId}";
        if (Redis::setnx($userTicketLockKey, 1)) {
            // 設置鎖的過期時間，例如 1 小時後自動釋放，避免死鎖
            Redis::expire($userTicketLockKey, 3600); // 1 小時
        } else {
            throw ValidationException::withMessages(['ticket' => '您已搶過此票，請勿重複操作。']);
        }


        // 1. Redis 原子性扣減庫存 (使用 Lua 腳本)
        $luaScript = <<<LUA
            local stockKey = KEYS[1]
            local currentStock = tonumber(redis.call('get', stockKey))
            if currentStock and currentStock > 0 then
                redis.call('decr', stockKey)
                return 1
            end
            return 0
LUA;
        $result = Redis::eval($luaScript, ["ticket:{$ticketId}:stock"], 0);

        if ($result === 0) {
            // 如果 Redis 庫存不足，釋放用戶鎖並拋出異常
            Redis::del($userTicketLockKey);
            throw ValidationException::withMessages(['ticket' => '抱歉，該票種庫存不足。']);
        }

        // 2. 異步處理訂單生成
        $orderId = 0;
        try {
            DB::transaction(function () use ($ticket, $userId, &$orderId) {
                $order = Order::create([
                    'user_id' => $userId,
                    'ticket_id' => $ticket->id,
                    'quantity' => 1, # 每次搶一張
                    'total_price' => $ticket->price,
                    'status' => Order::STATUS_PENDING,
                    'order_sn' => 'SN' . time() . uniqid(), # 簡單的訂單號
                ]);
                $orderId = $order->id;

                Log::info("Ticket {$ticket->id} stock reduced for user {$userId}. Order ID: {$orderId} created as PENDING.");
            });

            # 派發異步任務處理後續訂單流程
            # 使用 afterCommit() 確保只有當 Redis 扣減成功且 DB 事務提交後才派發 Job
            ProcessOrderJob::dispatch($orderId)->afterCommit();

        } catch (\Exception $e) {
            # 如果 DB 事務失敗，需要補回 Redis 庫存並釋放用戶鎖
            Redis::incr("ticket:{$ticketId}:stock");
            Redis::del($userTicketLockKey);
            Log::error("Failed to create order for user {$userId}, ticket {$ticketId}. Redis stock restored. Error: " . $e->getMessage());
            throw new \Exception('訂單建立失敗，庫存已恢復。請重試。');
        }

        return $orderId;
    }

    /**
     * 恢復指定訂單的票務庫存。
     *
     * @param int $orderId
     * @return bool
     */
    public function restoreTicketStockForOrder(int $orderId): bool
    {
        $order = Order::find($orderId);

        if (!$order || $order->status !== Order::STATUS_PENDING) {
            Log::info("Order {$orderId} not found or not in PENDING status, no stock to restore.");
            return false;
        }

        $ticketId = $order->ticket_id;
        $quantity = $order->quantity;

        DB::transaction(function () use ($order, $ticketId, $quantity) {
            # 更新訂單狀態為已取消
            $order->status = Order::STATUS_CANCELLED;
            $order->save();

            # 恢復 Redis 庫存
            Redis::incrby("ticket:{$ticketId}:stock", $quantity);
            Log::info("Restored {$quantity} stock for ticket {$ticketId} (Order ID: {$order->id}) due to timeout.");

            # 釋放用戶鎖（如果有的話，因為超時導致訂單取消，用戶可以重新搶）
            $userTicketLockKey = "user:ticket:lock:{$order->user_id}:{$order->ticket_id}";
            Redis::del($userTicketLockKey);
            Log::info("User lock for user {$order->user_id} and ticket {$order->ticket_id} released.");
        });

        return true;
    }
}
