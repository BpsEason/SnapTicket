<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis; # 引入 Redis Facade

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushdb(); # 確保每次測試前 Redis 乾淨
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Redis::flushdb(); # 確保每次測試後 Redis 乾淨
    }
}
