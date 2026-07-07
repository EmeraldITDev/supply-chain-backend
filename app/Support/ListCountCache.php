<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Short-TTL cache for paginated list total counts (avoids count(*) on every page load).
 * Version bumps on model writes invalidate stale totals without wildcard cache deletes.
 */
final class ListCountCache
{
    private const TTL_SECONDS = 60;

    public static function remember(string $scope, string $queryHash, callable $callback): int
    {
        $version = self::version($scope);
        $key = "list_count.{$scope}.v{$version}.{$queryHash}";

        return (int) Cache::remember(
            $key,
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): int => (int) $callback(),
        );
    }

    public static function bump(string $scope): void
    {
        if (! Cache::has("list_count_version.{$scope}")) {
            Cache::put("list_count_version.{$scope}", 1, now()->addDays(30));

            return;
        }

        Cache::increment("list_count_version.{$scope}");
    }

    private static function version(string $scope): int
    {
        return (int) Cache::get("list_count_version.{$scope}", 1);
    }
}
