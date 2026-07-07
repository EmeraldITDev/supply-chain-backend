<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Caches database table column listings to avoid repeated PostgreSQL
 * pg_catalog introspection on every request (each Schema::hasColumn call
 * can cost hundreds of ms on remote databases).
 */
final class TableColumnCache
{
    private const TTL_SECONDS = 86400;

    /**
     * @return list<string>
     */
    public static function columnsFor(string $table): array
    {
        if (! self::hasTable($table)) {
            return [];
        }

        return Cache::remember(
            self::cacheKey($table),
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): array => Schema::getColumnListing($table),
        );
    }

    /**
     * @param  list<string>  $desired
     * @return list<string>
     */
    public static function filterExisting(string $table, array $desired): array
    {
        $existing = array_flip(self::columnsFor($table));

        return array_values(array_filter(
            $desired,
            static fn (string $column): bool => isset($existing[$column]),
        ));
    }

    public static function hasTable(string $table): bool
    {
        return Cache::remember(
            'schema.table.'.$table,
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): bool => Schema::hasTable($table),
        );
    }

    public static function forget(string $table): void
    {
        Cache::forget(self::cacheKey($table));
        Cache::forget('schema.table.'.$table);
    }

    public static function forgetAll(): void
    {
        foreach (['m_r_f_s', 's_r_f_s', 'r_f_q_s', 'quotations', 'activities'] as $table) {
            self::forget($table);
        }
    }

    private static function cacheKey(string $table): string
    {
        return 'schema.columns.'.$table;
    }
}
