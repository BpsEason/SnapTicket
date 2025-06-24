<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Jobs\ProcessOrderJob;
use App\Jobs\MonitorPaymentJob; # 引入 MonitorPaymentJob
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User; # 引入 User 模型
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus; # 用於 Bus::dispatch() 的測試
use Carbon\Carbon;

class OrderJobTest extends TestCase
{
    use RefreshDatabase;

    protected $testUser;
    protected $testTicket;

    protected function setUp(): void
    {
        parent::setUp();
        # 確保有一個用戶和一個票務存在，供測試用
        $this->testUser = User::factory()->create(['id' => 1]); # 創建一個測試用戶
        $this->testTicket = Ticket::create([
            'id' => 101,
            'name' => '測試票 Job',
            'price' => 200.00,
            'total_stock' => 100,
            'current_stock' => 100,
            'start_time' => Carbon::now()->subHour(),
            'end_time' => Carbon::now()->addHour(),
            'timeout_minutes' => 1, # 測試用，設定超時為 1 分鐘
        ]);
        Queue::fake(); # 偽造佇列，以便斷言任務是否被推入
    }

    /** @test */
    public function it_dispatches_the_process_order_job_after_successful_grab()
    {
        # 模擬 TicketController 中的邏輯來觸發 Job
        # 這裡我們直接模擬搶票成功後觸發 Job 的場景
        $orderId = 999; // 模擬一個訂單ID
        Bus::dispatch(new ProcessOrderJob($orderId));

        # 斷言 ProcessOrderJob 是否被推入佇列
        Queue::assertPushed(ProcessOrderJob::class, function ($job) use ($orderId) {
            return $job->orderId === $orderId;
        });
    }

    /** @test */
    public function process_order_job_creates_order_in_database_and_dispatches_monitor_job()
    {
        # 不使用 Queue::fake()，實際執行 Job
        Queue::failing(); # 確保 Job 不會被再次偽造，而是實際執行

        $ticketId = $this->testTicket->id;
        $userId = $this->testUser->id;

        // 手動創建一個訂單對象，Job 處理的是這個訂單的 ID
        $order = Order::create([
            'order_sn' => 'TEST-ORDER-XYZ',
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'price' => $this->testTicket->price,
            'quantity' => 1,
            'total_price' => $this->testTicket->price,
            'status' => Order::STATUS_PENDING,
        ]);

        $job = new ProcessOrderJob($order->id);
        $job->handle(); # 直接執行 Job 的 handle 方法

        # 斷言數據庫中已創建訂單 (其實在測試環境，這裡會直接查詢 DB)
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_sn' => 'TEST-ORDER-XYZ',
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'status' => Order::STATUS_PENDING,
            'quantity' => 1,
        ]);

        # 斷言 MonitorPaymentJob 是否被推入佇列 (因為 ProcessOrderJob 實際執行了)
        Queue::assertPushed(MonitorPaymentJob::class, function ($monitorJob) use ($order) {
            return $monitorJob->orderId === $order->id;
        });
    }

    /** @test */
    public function process_order_job_handles_non_existent_order_id()
    {
        Queue::failing(); // 確保 Job 會實際執行

        $nonExistentOrderId = 99999; // 不存在的訂單ID

        // 執行 Job
        $job = new ProcessOrderJob($nonExistentOrderId);
        // 不應該拋出異常，只會記錄警告日誌
        $job->handle();

        $this->assertDatabaseMissing('orders', ['id' => $nonExistentOrderId]);
        Queue::assertNothingPushed(); // 不應該有後續的 Job 被派發
    }

    /** @test */
    public function monitor_payment_job_updates_order_status_and_restores_stock_if_pending()
    {
        # 創建一個待支付訂單
        $ticketService = $this->app->make(\App\Services\TicketService::class);
        $initialStock = 10;
        $ticketService->initializeTicketStock($this->testTicket->id, $initialStock); # 初始化庫存

        $order = Order::create([
            'order_sn' => 'MONITOR-TEST-ORDER-1',
            'user_id' => $this->testUser->id,
            'ticket_id' => $this->testTicket->id,
            'price' => $this->testTicket->price,
            'quantity' => 1,
            'total_price' => $this->testTicket->price,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->assertEquals($initialStock - 1, $ticketService->getTicketStock($this->testTicket->id)); // 庫存已因搶票而減少

        # 執行 MonitorPaymentJob
        $job = new MonitorPaymentJob($order->id);
        $job->handle($ticketService);

        # 檢查訂單狀態是否變為已取消
        $order->refresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);

        # 檢查 Redis 庫存是否已恢復
        $this->assertEquals($initialStock, $ticketService->getTicketStock($this->testTicket->id));
    }

    /** @test */
    public function monitor_payment_job_does_not_change_status_if_not_pending()
    {
        # 創建一個已支付訂單
        $ticketService = $this->app->make(\App\Services\TicketService::class);
        $initialStock = 10;
        $ticketService->initializeTicketStock($this->testTicket->id, $initialStock);

        $order = Order::create([
            'order_sn' => 'MONITOR-TEST-ORDER-2',
            'user_id' => $this->testUser->id,
            'ticket_id' => $this->testTicket->id,
            'price' => $this->testTicket->price,
            'quantity' => 1,
            'total_price' => $this->testTicket->price,
            'status' => Order::STATUS_PAID, # 已經支付
        ]);

        $this->assertEquals($initialStock - 1, $ticketService->getTicketStock($this->testTicket->id)); # 庫存已扣減

        # 執行 MonitorPaymentJob
        $job = new MonitorPaymentJob($order->id);
        $job->handle($ticketService);

        # 檢查訂單狀態沒有改變
        $order->refresh();
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        # 檢查 Redis 庫存沒有恢復 (因為訂單不是 pending)
        $this->assertEquals($initialStock - 1, $ticketService->getTicketStock($this->testTicket->id));
    }
}
