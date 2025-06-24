<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * 處理傳入的請求。
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $keyType 限制的鍵類型 (例如 'ip' 或 'user_id')
     * @param  int  $maxAttempts 允許的最大嘗試次數
     * @param  int  $decayMinutes 多少分鐘後重置嘗試次數
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    public function handle(Request $request, Closure $next, $keyType = 'ip', $maxAttempts = 60, $decayMinutes = 1): Response
    {
        $limiterKey = match ($keyType) {
            'user_id' => $request->user() ? 'user:' . $request->user()->id : 'ip:' . $request->ip(),
            default => 'ip:' . $request->ip(),
        };

        // 為每個限制鍵生成唯一的 Redis Key，加上前綴以避免衝突
        $rateLimitRedisKey = 'rate_limit:' . sha1($limiterKey);

        // 嘗試獲取或設置計數器
        // SETNX 返回 1 表示設置成功 (第一次請求)，返回 0 表示 Key 已存在
        if (Redis::rawCommand('SET', $rateLimitRedisKey, 1, 'EX', $decayMinutes * 60, 'NX') === null) {
            // Key 存在，表示不是第一次請求，增加計數器
            $currentAttempts = Redis::incr($rateLimitRedisKey);
        } else {
            // Key 不存在，這是第一次請求，計數器已經設置為 1
            $currentAttempts = 1;
        }

        if ($currentAttempts > $maxAttempts) {
            // 計算剩餘時間
            $ttl = Redis::ttl($rateLimitRedisKey);
            $retryAfter = $ttl > 0 ? $ttl : 0; // 確保不返回負值

            throw new ThrottleRequestsException('請求過於頻繁，請稍後再試。', null, ['Retry-After' => max(0, $retryAfter)]);
        }

        $response = $next($request);

        // 在響應頭中添加限流信息
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => max(0, $maxAttempts - $currentAttempts),
            'Retry-After' => max(0, Redis::ttl($rateLimitRedisKey)),
        ]);

        return $response;
    }
}
