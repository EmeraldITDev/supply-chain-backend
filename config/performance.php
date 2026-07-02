<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slow Query Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, individual SQL statements exceeding slow_query_ms are
    | logged with route context. Enable in production via LOG_SLOW_QUERIES=true.
    |
    */

    'log_slow_queries' => env('LOG_SLOW_QUERIES', env('APP_ENV') !== 'production'),

    'slow_query_ms' => (int) env('SLOW_QUERY_MS', 200),

    'slow_request_ms' => (int) env('SLOW_REQUEST_MS', 1000),

    'max_queries_per_request' => (int) env('MAX_QUERIES_PER_REQUEST', 50),

    'expose_query_headers' => env('EXPOSE_QUERY_HEADERS', false),

];
