<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access point for the analytics baseline configuration.
 * Values are set once by EstablishAnalyticsBaseline and never recalculated.
 */
class AnalyticsBaseline
{
    private static ?bool $established = null;

    private static ?Carbon $effectiveAtCached = null;

    /**
     * The effective baseline timestamp, or null if not yet established.
     */
    public static function effectiveAt(): ?Carbon
    {
        if (static::$effectiveAtCached === null) {
            static::$effectiveAtCached = static::load();
        }

        return static::$effectiveAtCached;
    }

    /**
     * True when the baseline has been established at production cutover.
     */
    public static function isEstablished(): bool
    {
        return static::effectiveAt() !== null;
    }

    /**
     * Whether the given timestamp falls within the new analytics baseline period.
     * Pre-baseline records should be classified as legacy for performance metrics.
     */
    public static function isNewPeriod(Carbon $timestamp): bool
    {
        $baseline = static::effectiveAt();

        return $baseline !== null && $timestamp->gte($baseline);
    }

    /**
     * The full baseline configuration array for API payloads.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $at = static::effectiveAt();

        return [
            'established'    => $at !== null,
            'effective_at'   => $at?->toIso8601String(),
            'timezone'       => static::setting('timezone'),
            'version_ref'    => static::setting('version_ref'),
            'established_by' => static::setting('established_by'),
            'established_at' => static::setting('established_at'),
        ];
    }

    /** Flush the static cache (for tests). */
    public static function flush(): void
    {
        static::$effectiveAtCached = null;
        static::$established       = null;
    }

    private static function load(): ?Carbon
    {
        try {
            $value = DB::table('system_settings')
                ->where('category', 'analytics_baseline')
                ->where('key', 'effective_at')
                ->value('value');

            if (! $value) {
                return null;
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setting(string $key): ?string
    {
        try {
            return DB::table('system_settings')
                ->where('category', 'analytics_baseline')
                ->where('key', $key)
                ->value('value');
        } catch (\Throwable) {
            return null;
        }
    }
}
