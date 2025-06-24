<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken; # 引入 PersonalAccessToken

class TicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param TicketService $ticketService
     * @return void
     */
    public function run(TicketService $ticketService)
    {
        # 清理舊數據，確保每次種子是乾淨的
        // 禁用外鍵檢查以便於 TRUNCATE
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Ticket::truncate();
        \App\Models\Order::truncate(); // 清空 Order 表
        PersonalAccessToken::truncate(); // 清空所有 Sanctum 令牌
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');


        # 創建測試用戶 (用於 API 認證)
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        # 為測試用戶生成 Sanctum 令牌
        $token = $user->createToken('stress_test_token', ['*'], Carbon::now()->addYears(1))->plainTextToken;
        $this->command->info("--- 測試用戶 API 令牌 (請記錄，用於壓力測試) ---");
        $this->command->info("用戶 ID: {$user->id}");
        $this->command->info("令牌: {$token}");
        $this->command->info("-----------------------------------------------------");
        
        # 將令牌寫入 .env 檔案，方便壓力測試使用
        # 這裡直接在 .env 末尾追加，請確保 Docker-compose.yml 能讀取到此變數
        file_put_contents(base_path('.env'), PHP_EOL . "TEST_API_TOKEN={$token}" . PHP_EOL, FILE_APPEND);


        # 創建測試票務
        $ticket = Ticket::create([
            'name' => 'SnapTicket 演唱會門票',
            'description' => '2025 年熱門演唱會門票，數量有限，欲購從速！',
            'total_stock' => 5000,
            'current_stock' => 5000, # 初始值，實際庫存以 Redis 為準
            'price' => 1280.00,
            'start_time' => Carbon::now()->subHour(), # 一小時前開始，方便測試
            'end_time' => Carbon::now()->addDays(7), # 七天後結束
            'timeout_minutes' => 5, # 設定為 5 分鐘超時
        ]);

        # 初始化 Redis 庫存
        $ticketService->initializeTicketStock($ticket->id, $ticket->total_stock);

        $this->command->info("已建立測試用戶和演唱會門票 (ID: {$ticket->id})。");
    }
}
