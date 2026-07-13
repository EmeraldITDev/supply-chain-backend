<?php

namespace App\Support;

/**
 * Short-TTL cache for report/analytics aggregates.
 *
 * Routes through FastCache so CACHE_STORE=database does not add ~850ms
 * remote SQL round-trips on every report page load.
 */
class ReportCache
{
    public const TTL_SECONDS = 300;

    public static function remember(string $key, callable $callback): mixed
    {
        return FastCache::store()->remember($key, self::TTL_SECONDS, $callback);
    }

    /**
     * @param  array<int|string, mixed>  $parts
     */
    public static function key(string $prefix, array $parts): string
    {
        return 'scm_report:'.$prefix.':'.md5(json_encode($parts));
    }
}
