<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ReportCache
{
    public const TTL_SECONDS = 300;

    public static function remember(string $key, callable $callback): mixed
    {
        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }

    /**
     * @param  array<int|string, mixed>  $parts
     */
    public static function key(string $prefix, array $parts): string
    {
        return 'scm_report:'.$prefix.':'.md5(json_encode($parts));
    }
}
