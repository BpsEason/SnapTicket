# SnapTicket - 高併發搶票系統

![PHP](https://img.shields.io/badge/PHP-8.2-blue) ![Laravel](https://img.shields.io/badge/Laravel-10.x-red) ![Swoole](https://img.shields.io/badge/Swoole-4.x-green) ![Redis](https://img.shields.io/badge/Redis-7.0-orange) ![Docker](https://img.shields.io/badge/Docker-Enabled-blue) ![License](https://img.shields.io/badge/License-MIT-brightgreen)

**SnapTicket** 是一個基於 **Laravel**、**Redis** 和 **Swoole** 打造的高併發搶票系統，專為大規模票務搶購場景設計。本專案僅包含應用層核心代碼（模型、控制器、服務、遷移等），不包含 Laravel 框架基礎代碼（例如 `vendor/` 目錄）。您需要通過 Composer 安裝 Laravel 框架及其依賴來運行專案。系統利用原子性庫存管理、非同步訂單處理和壓力測試工具，實現高效、穩定的搶票體驗。

## 專案亮點
- **高併發搶票**：採用 Redis Lua 腳本實現庫存扣減的原子性，防止超賣。
- **非同步訂單處理**：支援支付超時自動取消訂單並恢復庫存，優化使用者體驗。
- **速率限制**：基於 Redis 的 API 速率限制中介層，防止濫用和惡意請求。
- **壓力測試工具**：內建 Guzzle 模擬高併發請求，生成 QPS 和成功率報告。
- **容器化部署**：整合 Docker 環境（PHP 8.2、MySQL、Redis、Nginx），一鍵部署。
- **安全認證**：使用 Laravel Sanctum 提供安全的 API 令牌認證。
- **全面測試**：功能測試覆蓋搶票邏輯、訂單處理和併發控制，確保系統穩定。

## 技術棧
- **後端框架**：Laravel 10.x
- **高併發服務器**：Swoole 4.x
- **緩存與隊列**：Redis 7.0
- **資料庫**：MySQL 8.0
- **容器化**：Docker（PHP-FPM、Nginx、MySQL、Redis）
- **壓力測試**：Guzzle 7.x
- **認證**：Laravel Sanctum

## 系統架構圖
以下是 SnapTicket 的系統架構，展示各模組間的交互流程：

```mermaid
graph TD
    A[使用者] -->|HTTP 請求| B[Nginx]
    B -->|代理| C[Swoole HTTP 服務器]
    C -->|處理請求| D[Laravel 應用]
    D -->|搶票邏輯| E[TicketService]
    E -->|原子性扣減| F[Redis: 庫存管理]
    E -->|生成訂單| G[MySQL: 訂單與票務數據]
    D -->|非同步任務| H[Redis 隊列]
    H -->|處理訂單| I[Swoole 隊列工作進程]
    I -->|支付監控| J[MonitorPaymentJob]
    J -->|超時恢復庫存| E
    A -->|壓力測試| K[SimulatedClient 命令]
    K -->|併發請求| B
```

## 安裝與使用
### 環境要求
- Docker 和 Docker Compose
- PHP 8.2（用於本地執行 Composer）
- Composer（用於安裝 Laravel 框架和依賴）
- Bash 環境（用於執行腳本）

### 安裝步驟
**重要提示**：本專案僅包含應用層核心代碼（例如 `app/`、`config/`、`database/` 等），不包含 Laravel 框架基礎代碼（例如 `vendor/` 目錄）。您必須執行 `composer install` 來安裝 Laravel 框架及其依賴。

1. **克隆專案**：
   ```bash
   git clone https://github.com/BpsEason/SnapTicket.git
   cd SnapTicket
   ```

2. **安裝 PHP 依賴**：
   執行以下命令拉取 Laravel 框架及專案所需依賴（定義在 `composer.json` 中）：
   ```bash
   composer install
   ```
   如果本地未安裝 Composer，可在後續 Docker 容器內執行。

3. **啟動 Docker 環境**：
   啟動包含 PHP 8.2、MySQL、Redis 和 Nginx 的 Docker 環境：
   ```bash
   docker-compose up -d --build
   ```
   如果未在本地執行 `composer install`，在容器內完成：
   ```bash
   docker-compose exec app composer install
   ```

4. **初始化應用**：
   生成 Laravel 應用密鑰並清理過期 Sanctum 令牌：
   ```bash
   docker-compose exec app php artisan key:generate
   docker-compose exec app php artisan sanctum:prune-expired --hours=24
   ```

5. **運行資料庫遷移與種子數據**：
   創建資料庫表並生成測試用戶與票務數據：
   ```bash
   docker-compose exec app php artisan migrate --seed
   ```
   **重要**：記錄控制台輸出的 `TEST_API_TOKEN`，用於 API 認證和壓力測試。該令牌也會自動寫入 `.env` 文件。

6. **啟動 Swoole 服務**：
   啟動 Swoole HTTP 服務器和隊列工作進程：
   ```bash
   docker-compose exec app php artisan swoole:http start
   docker-compose exec app php artisan swoole:queue start
   ```

### 訪問
- **Web 頁面**：`http://localhost`
- **搶票 API**：`http://localhost/api/ticket/grab/{ticket_id}`
- **庫存查詢 API**：`http://localhost/api/ticket/stock/{ticket_id}`

## 關鍵代碼片段
以下是 SnapTicket 的核心代碼片段，展示搶票邏輯的實現，並附上詳細註解：

### Redis Lua 腳本（原子性庫存扣減）
檔案：`app/Services/TicketService.php`

```php
// 使用 Lua 腳本確保庫存扣減的原子性，防止併發超賣
$luaScript = <<<LUA
    local stockKey = KEYS[1]
    local currentStock = tonumber(redis.call('get', stockKey))
    if currentStock and currentStock > 0 then
        redis.call('decr', stockKey) // 庫存減 1
        return 1 // 扣減成功
    end
    return 0 // 庫存不足
LUA;
$result = Redis::eval($luaScript, ["ticket:{$ticketId}:stock"], 0);

if ($result === 0) {
    // 庫存不足時，釋放用戶鎖並拋出異常
    Redis::del($userTicketLockKey);
    throw ValidationException::withMessages(['auto' => '抱歉，該票種庫存不足。']);
}
```

### 搶票邏輯（TicketService）
檔案：`app/Services/TicketService.php`

```php
public function grabTicket(int $ticketId, int $userId): int
{
    $ticket = Ticket::findOrFail($ticketId); // 查詢票務

    // 檢查活動時間，確保搶票在有效時間內
    $now = Carbon::now();
    if ($now->lt($ticket->start_time)) {
        throw ValidationException::withMessages(['auto' => '搶票活動尚未開始。']);
    }
    if ($now->gt($ticket->end_time)) {
        throw ValidationException::withMessages(['auto' => '搶票活動已結束。']);
    }

    // 防止用戶重複搶票，使用 Redis 鎖
    $userTicketLockKey = "user:ticket:lock:{$userId}:{$ticketId}";
    if (Redis::setnx($userTicketLockKey, 1)) {
        Redis::expire($userTicketLockKey, 3600); // 鎖定 1 小時
    } else {
        throw ValidationException::withMessages(['auto' => '您已搶過此票，請勿重複操作。']);
    }

    // 執行 Lua 腳本扣減庫存
    $result = Redis::eval($luaScript, ["ticket:{$ticketId}:stock"], 0);
    if ($result === 0) {
        Redis::del($userTicketLockKey);
        throw ValidationException::withMessages(['auto' => '抱歉，該票種庫存不足。']);
    }

    // 在資料庫事務中生成訂單
    $orderId = 0;
    try {
        DB::transaction(function () use ($ticket, $userId, &$orderId) {
            $order = Order::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'quantity' => 1,
                'total_price' => $ticket->price,
                'status' => Order::STATUS_PENDING,
                'order_sn' => 'SN' . time() . uniqid(),
            ]);
            $orderId = $order->id;
        });

        // 派發非同步任務處理訂單
        ProcessOrderJob::dispatch($orderId)->afterCommit();
    } catch (\Exception $e) {
        // 事務失敗時恢復庫存並釋放鎖
        Redis::incr("ticket:{$ticketId}:stock");
        Redis::del($userTicketLockKey);
        throw new \Exception('訂單建立失敗，庫存已恢復。請重試。');
    }

    return $orderId;
}
```

### 壓力測試命令
檔案：`app/Console/Commands/SimulatedClient.php`

```php
public function handle()
{
    // 初始化壓力測試參數
    $ticketId = $this->argument('ticket_id');
    $users = (int)$this->option('users');
    $requestsPerUser = (int)$this->option('requests_per_user');
    $concurrency = (int)$this->option('concurrency');
    $targetUrl = rtrim($this->option('target_url'), '/') . "/{$ticketId}";
    $apiToken = $this->option('token') ?: env('TEST_API_TOKEN', '');

    // 使用 Guzzle 併發請求模擬高併發搶票
    $client = new Client([
        'timeout' => 30,
        'connect_timeout' => 5,
        'headers' => [
            'Authorization' => 'Bearer ' . $apiToken,
            'Accept' => 'application/json',
        ],
    ]);

    $totalRequests = $users * $requestsPerUser;
    $startTime = microtime(true);
    $successCount = 0;
    $failureCount = 0;

    // 創建併發請求池
    $pool = new Pool($client, $requests, [
        'concurrency' => $concurrency,
        'fulfilled' => function ($response) use (&$successCount) {
            if ($response->getStatusCode() === 201) {
                $successCount++;
            }
        },
        'rejected' => function ($reason) use (&$failureCount) {
            $failureCount++;
        },
    ]);

    // 執行並等待所有請求完成
    $promise = $pool->promise();
    $promise->wait();

    // 生成壓力測試報告
    $duration = microtime(true) - $startTime;
    $qps = ($duration > 0) ? ($totalRequests / $duration) : 0;
    $this->info("總請求數：{$totalRequests}");
    $this->info("成功請求數：{$successCount}");
    $this->info("失敗請求數：{$failureCount}");
    $this->info("總耗時：" . number_format($duration, 2) . " 秒");
    $this->info("每秒請求量 (QPS)：" . number_format($qps, 2));
}
```

## API 文檔
### 搶票 API
- **URL**：`POST /api/ticket/grab/{ticket_id}`
- **認證**：Bearer Token（Sanctum）
- **請求範例**：
  ```bash
  curl -X POST http://localhost/api/ticket/grab/1 \
       -H "Authorization: Bearer ${TEST_API_TOKEN}" \
       -H "Accept: application/json"
  ```
- **響應**：
  ```json
  {
      "message": "搶票成功，訂單已生成！",
      "order_id": 1
  }
  ```

### 庫存查詢 API
- **URL**：`GET /api/ticket/stock/{ticket_id}`
- **認證**：Bearer Token（Sanctum）
- **請求範例**：
  ```bash
  curl http://localhost/api/ticket/stock/1 \
       -H "Authorization: Bearer ${TEST_API_TOKEN}" \
       -H "Accept: application/json"
  ```
- **響應**：
  ```json
  {
      "ticket_id": 1,
      "stock": 5000
  }
  ```

## 壓力測試
模擬高併發搶票：
```bash
docker-compose exec app php artisan stress:grab 1 --users=100 --requests_per_user=10 --concurrency=50 --token=${TEST_API_TOKEN}
```
- `ticket_id`：票務 ID（預設為 1，來自種子數據）
- `--users`：模擬用戶數
- `--requests_per_user`：每用戶請求數
- `--concurrency`：最大併發數
- `--token`：API 令牌（從 `migrate --seed` 輸出或 `.env` 获取）

## 運行測試
執行功能測試：
```bash
docker-compose exec app php artisan test
```
測試檔案：
- `tests/Feature/TicketServiceTest.php`：搶票邏輯與併發控制
- `tests/Feature/OrderJobTest.php`：訂單處理與支付超時

## 常見問題 (FAQ)
以下是 SnapTicket 專案的設計與技術選型常見問題，幫助面試官或使用者了解系統的核心實現：

### Q1: SnapTicket 如何在高併發下防止超賣？
**A1**: 本系統採用 **Redis Lua 腳本**實現庫存扣減的原子性。當搶票請求到達時，Lua 腳本會原子性地檢查庫存並進行扣減。如果庫存不足，則拒絕請求。這確保了即使在極端併發情況下，庫存操作也是安全的，不會出現超賣。

### Q2: 為什麼選擇 Redis 進行庫存管理而不是直接操作 MySQL？
**A2**: 選擇 Redis 是基於其**高性能**和**原子性操作**特性。Redis 是記憶體數據庫，讀寫速度遠超 MySQL，在高併發場景下可避免數據庫瓶頸。Redis 的 `DECR` 命令或 Lua 腳本能以非阻塞方式實現原子操作，提升系統吞吐量。

### Q3: 系統如何處理用戶重複搶票的問題？
**A3**: 我們在 Redis 中為每個用戶和票種設置了**唯一鎖（`user:ticket:lock`）**。使用 `SETNX` 命令檢查鎖是否存在，若成功則設置過期時間並繼續搶票流程；若失敗則拒絕請求，防止重複搶票。

### Q4: 為什麼需要非同步訂單處理？具體流程是怎樣的？
**A4**: 非同步處理解耦了核心搶票邏輯與耗時操作，提升響應速度和併發能力。流程如下：
1. Redis 庫存扣減成功後，在 MySQL 創建 `pending` 狀態訂單。
2. 派發 `ProcessOrderJob` 到 Redis 隊列，由 Swoole 工作進程非同步消費。
3. `ProcessOrderJob` 延遲派發 `MonitorPaymentJob`，檢查支付超時。
4. 若訂單仍為 `pending`，則取消訂單並恢復 Redis 庫存。

### Q5: Swoole 在 SnapTicket 中扮演什麼角色？為何不用 PHP-FPM？
**A5**: Swoole 提供**高性能 HTTP 服務**和**非同步隊列處理**：
- **Swoole HTTP Server**：常駐記憶體運行 Laravel，減少初始化開銷，提升 QPS。
- **Swoole Queue Worker**：消費 Redis 隊列任務，實現訂單非同步處理。
相較於 PHP-FPM，Swoole 的協程模型以更低資源處理高併發請求，提升效率。

### Q6: 如何確保 Redis 庫存與 MySQL 訂單數據的一致性？
**A6**: 採用「先 Redis 扣減，後 MySQL 事務創建訂單」的策略。若 MySQL 事務失敗，會回滾並恢復 Redis 庫存（`Redis::incr`）並釋放用戶鎖。Job 使用 `afterCommit()` 確保僅在事務提交後派發，保證數據一致性。

### Q7: 壓力測試工具如何使用和評估結果？
**A7**: 使用 `php artisan stress:grab` 命令模擬高併發搶票，需提供 `TEST_API_TOKEN`。報告包含總請求數、成功數、失敗數、耗時和 QPS。評估時關注 QPS 是否達標、成功率是否接近 100%（排除庫存不足失敗），並監控服務器資源使用。

## 貢獻
歡迎提交 Issue 或 Pull Request！請遵循以下步驟：
1. Fork 本倉庫
2. 創建特性分支（`git checkout -b feature/xxx`）
3. 提交變更（`git commit -m 'Add xxx feature'`）
4. 推送到分支（`git push origin feature/xxx`）
5. 提交 Pull Request
