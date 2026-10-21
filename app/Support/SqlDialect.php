<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * SQL fragments that differ between PostgreSQL and MySQL/MariaDB, so the same
 * queries run on both.
 */
final class SqlDialect
{
    /**
     * Case-insensitive LIKE operator. MySQL/MariaDB LIKE is already
     * case-insensitive under the default *_ci collations.
     */
    public static function like(): string
    {
        return self::driver() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Expression for the number of days (fractional) from $from to $to,
     * where both are timestamp column names or expressions.
     */
    public static function daysBetween(string $from, string $to): string
    {
        return match (self::driver()) {
            'pgsql' => "EXTRACT(EPOCH FROM ({$to} - {$from})) / 86400.0",
            'sqlite' => "(julianday({$to}) - julianday({$from}))",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to}) / 86400.0",
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
