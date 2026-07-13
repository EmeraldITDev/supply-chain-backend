<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Cache indirection for hot, high-frequency internal caches (schema columns,
 * list counts, dashboard stats).
 *
 * On this deployment the default cache store is `database`, which turns EVERY
 * cache read/write into a remote SQL round-trip (~850ms each on the production
 * DB). That makes caching actively slower than the query it was meant to avoid.
 *
 * These particular caches are short-TTL, per-instance-safe derived values, so we
 * route them to a local, in-process-fast store (`file`) instead of the slow
 * database store. This removes dozens of ~850ms cache queries per request.
 */
final class FastCache
{
    private static ?string $resolved = null;

    public static function store(): Repository
    {
        $name = self::resolveStoreName();

        return $name === null ? Cache::store() : Cache::store($name);
    }

    private static function resolveStoreName(): ?string
    {
        if (self::$resolved !== null) {
            return self::$resolved === '' ? null : self::$resolved;
        }

        // Explicit override wins (e.g. set CACHE_FAST_STORE=redis when Redis exists).
        $explicit = (string) config('cache.fast_store', env('CACHE_FAST_STORE', ''));
        if ($explicit !== '') {
            self::$resolved = $explicit;

            return $explicit;
        }

        $default = (string) config('cache.default', 'file');

        // The database store is the slow path — fall back to local file cache.
        if ($default === 'database') {
            self::$resolved = 'file';

            return 'file';
        }

        self::$resolved = '';

        return null;
    }
}
