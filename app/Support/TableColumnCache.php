<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Caches database table column listings to avoid repeated PostgreSQL
 * pg_catalog introspection on every request (each Schema::hasColumn call
 * can cost hundreds of ms on remote databases).
 */
final class TableColumnCache
{
    private const TTL_SECONDS = 86400;

    /** @var array<string, list<string>|bool> */
    private static array $requestMemo = [];

    /**
     * @return list<string>
     */
    public static function columnsFor(string $table): array
    {
        if (! self::hasTable($table)) {
            return [];
        }

        $memoKey = 'cols:'.$table;
        if (isset(self::$requestMemo[$memoKey]) && is_array(self::$requestMemo[$memoKey])) {
            /** @var list<string> $cached */
            $cached = self::$requestMemo[$memoKey];

            return $cached;
        }

        $columns = FastCache::store()->remember(
            self::cacheKey($table),
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): array => Schema::getColumnListing($table),
        );

        self::$requestMemo[$memoKey] = $columns;

        return $columns;
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
        $memoKey = 'table:'.$table;
        if (array_key_exists($memoKey, self::$requestMemo)) {
            return (bool) self::$requestMemo[$memoKey];
        }

        $exists = FastCache::store()->remember(
            'schema.table.'.$table,
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): bool => Schema::hasTable($table),
        );
        self::$requestMemo[$memoKey] = $exists;

        return $exists;
    }

    public static function forget(string $table): void
    {
        unset(self::$requestMemo['cols:'.$table], self::$requestMemo['table:'.$table]);
        FastCache::store()->forget(self::cacheKey($table));
        FastCache::store()->forget('schema.table.'.$table);
    }

    public static function forgetAll(): void
    {
        self::$requestMemo = [];
        foreach (['m_r_f_s', 's_r_f_s', 'r_f_q_s', 'quotations', 'activities'] as $table) {
            self::forget($table);
        }
    }

    private static function cacheKey(string $table): string
    {
        return 'schema.columns.'.$table;
    }
}
