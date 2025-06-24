<?php

return [
    'http_server' => [
        'host' => env('SWOOLE_HTTP_HOST', '127.0.0.0'),
        'port' => env('SWOOLE_HTTP_PORT', 9501),
        'options' => [
            'worker_num' => env('SWOOLE_WORKER_NUM', swoole_cpu_num() * 2),
            'task_worker_num' => env('SWOOLE_TASK_WORKER_NUM', swoole_cpu_num()),
            'max_request' => env('SWOOLE_MAX_REQUEST', 3000),
            'daemonize' => env('SWOOLE_DAEMONIZE', false),
            'pid_file' => storage_path('logs/swoole_http_server.pid'),
            'log_file' => storage_path('logs/swoole_http_server.log'),
            'log_level' => SWOOLE_LOG_INFO,
            'open_tcp_nodelay' => true,
            'package_max_length' => 8 * 1024 * 1024,
            'buffer_output_size' => 2 * 1024 * 1024,
            'enable_static_handler' => true,
            'document_root' => public_path(),
            'http_compression' => true,
            'log_rotation' => true, # 啟用日誌輪轉
            'log_max_files' => 7,   # 保留 7 天日誌
        ],
    ],
    'queue_worker' => [
        'worker_num' => env('SWOOLE_QUEUE_WORKER_NUM', swoole_cpu_num()),
        'max_requests' => env('SWOOLE_QUEUE_MAX_REQUESTS', 1000),
        'daemonize' => env('SWOOLE_QUEUE_DAEMONIZE', false),
        'pid_file' => storage_path('logs/swoole_queue_worker.pid'),
        'log_file' => storage_path('logs/swoole_queue_worker.log'),
        'log_level' => SWOOLE_LOG_INFO,
        'timeout' => env('SWOOLE_QUEUE_TIMEOUT', 60), # 消費者超時時間
        'sleep' => env('SWOOLE_QUEUE_SLEEP', 3),     # 空閒時休眠時間
        'log_rotation' => true, # 啟用日誌輪轉
        'log_max_files' => 7,   # 保留 7 天日誌
    ],
    'tables' => [
        env('SWOOLE_TABLE_TICKET_STOCK', 'snapticket_stock') => [
            'columns' => [
                ['name' => 'stock', 'type' => \Swoole\Table::TYPE_INT, 'size' => 8],
            ],
            'size' => 1024, # 預計存儲的票務類型數量
        ],
    ],
];
