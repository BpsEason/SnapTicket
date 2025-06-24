<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\TicketService;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class TicketServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $ticketService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ticketService = app()->make(TicketService::class);
        Queue::fake(); // 偽造佇列，以便斷言任務是否被推入
    }

    /** @test */
    public function it_initializes_ticket_stock_in_redis()
    {
        $ticketId = 1;
        $stock = 100;
        $this->ticketService->initializeTicketStock($ticketId, $stock);

        $redisStock = Redis::get("ticket:{$ticketId}:stock");
        $this->assertEquals($stock, (int)$redisStock);
    }

    /** @test */
    public function it_returns_correct_ticket_stock()
    {
        $ticketId = 1;
        $stock = 50;
        Redis::set("ticket:{$ticketId}:stock", $stock);

        $currentStock = $this->ticketService->getTicketStock($ticketId);
        $this->assertEquals($stock, $currentStock);
    }

    /** @test */
    public function it_successfully_grabs_a_ticket_and_creates_order()
    {
        $user = User::factory()->create(['id' => 1]);
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Test Concert',
            'total_stock' => 10,
            'current_stock' => 10,
            'price' => 100.00,
            'start_time' => Carbon::now()->subHour(),
            'end_time' => Carbon::now()->addHour(),
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 10);

        $orderId = $this->ticketService->grabTicket($ticket->id, $user->id);

        $this->assertGreaterThan(0, $orderId);
        $this->assertEquals(9, $this->ticketService->getTicketStock($ticket->id));
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
            'status' => Order::STATUS_PENDING,
        ]);
        Queue::assertPushed(\App\Jobs\ProcessOrderJob::class);
    }

    /** @test */
    public function it_throws_exception_when_ticket_is_out_of_stock()
    {
        $user = User::factory()->create(['id' => 1]);
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Test Concert',
            'total_stock' => 1,
            'current_stock' => 1,
            'price' => 100.00,
            'start_time' => Carbon::now()->subHour(),
            'end_time' => Carbon::now()->addHour(),
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('抱歉，該票種庫存不足。');

        $this->ticketService->grabTicket($ticket->id, $user->id);
    }

    /** @test */
    public function it_throws_exception_when_grabbing_before_start_time()
    {
        $user = User::factory()->create(['id' => 1]);
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Test Concert',
            'total_stock' => 100,
            'current_stock' => 100,
            'price' => 100.00,
            'start_time' => Carbon::now()->addHour(), // 未開始
            'end_time' => Carbon::now()->addHours(2),
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 100);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('搶票活動尚未開始。');

        $this->ticketService->grabTicket($ticket->id, $user->id);
    }

    /** @test */
    public function it_throws_exception_when_grabbing_after_end_time()
    {
        $user = User::factory()->create(['id' => 1]);
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Test Concert',
            'total_stock' => 100,
            'current_stock' => 100,
            'price' => 100.00,
            'start_time' => Carbon::now()->subHours(2),
            'end_time' => Carbon::now()->subHour(), // 已結束
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 100);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('搶票活動已結束。');

        $this->ticketService->grabTicket($ticket->id, $user->id);
    }

    /** @test */
    public function it_prevents_duplicate_grab_for_same_user_and_ticket()
    {
        $user = User::factory()->create(['id' => 1]);
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Test Concert',
            'total_stock' => 100,
            'current_stock' => 100,
            'price' => 100.00,
            'start_time' => Carbon::now()->subHour(),
            'end_time' => Carbon::now()->addHour(),
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 100);

        // 第一次搶票成功
        $this->ticketService->grabTicket($ticket->id, $user->id);

        // 第二次搶票應失敗
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('您已搶過此票，請勿重複操作。');
        $this->ticketService->grabTicket($ticket->id, $user->id);
    }

    /** @test */
    public function it_handles_concurrent_requests_without_overselling()
    {
        $users = User::factory()->count(10)->create(); // 創建多個用戶
        $ticket = Ticket::create([
            'id' => 1,
            'name' => 'Concurrent Test Ticket',
            'total_stock' => 2, // 總庫存只有 2
            'current_stock' => 2,
            'price' => 100.00,
            'start_time' => Carbon::now()->subHour(),
            'end_time' => Carbon::now()->addHour(),
            'timeout_minutes' => 15,
        ]);
        $this->ticketService->initializeTicketStock($ticket->id, 2);

        // 獲取一個 API 令牌用於測試請求
        $testUser = $users->first();
        $apiToken = $testUser->createToken('test_token')->plainTextToken;

        $client = new Client();
        $requests = function () use ($ticket, $users, $apiToken) {
            foreach ($users as $user) {
                yield new Request('POST', "http://nginx/api/ticket/grab/{$ticket->id}", [
                    'Authorization' => 'Bearer ' . $apiToken, // 實際請求需要帶上令牌
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]);
            }
        };

        // 使用 Guzzle Pool 模擬併發請求
        $pool = new Pool($client, $requests(), [
            'concurrency' => 5, // 模擬 5 個併發請求
            'fulfilled' => function ($response) {
                // Log::info('請求成功', ['status' => $response->getStatusCode(), 'body' => (string)$response->getBody()]);
            },
            'rejected' => function ($reason) {
                // Log::error('請求失敗', ['reason' => $reason->getMessage()]);
            },
        ]);

        // 等待所有請求完成
        $promise = $pool->promise();
        $promise->wait();

        $successCount = Order::where('ticket_id', $ticket->id)->count();
        $this->assertEquals(2, $successCount, '不應超賣，僅允許 2 張票被搶');
        $this->assertEquals(0, $this->ticketService->getTicketStock($ticket->id));
    }
}
