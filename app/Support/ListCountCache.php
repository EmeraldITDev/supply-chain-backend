<?php

namespace App\Support;

/**
 * Short-TTL cache for paginated list total counts (avoids count(*) on every page load).
 * Version bumps on model writes invalidate stale totals without wildcard cache deletes.
 *
 * Uses FastCache (local file store) rather than the slow database cache store so
 * count caching doesn't add ~850ms remote cache queries per list request.
 */
final class ListCountCache
{
    private const TTL_SECONDS = 60;

    public static function remember(string $scope, string $queryHash, callable $callback): int
    {
        $version = self::version($scope);
        $key = "list_count.{$scope}.v{$version}.{$queryHash}";

        return (int) FastCache::store()->remember(
            $key,
            now()->addSeconds(self::TTL_SECONDS),
            static fn (): int => (int) $callback(),
        );
    }

    public static function bump(string $scope): void
    {
        $cache = FastCache::store();

        if (! $cache->has("list_count_version.{$scope}")) {
            $cache->put("list_count_version.{$scope}", 1, now()->addDays(30));

            return;
        }

        $cache->increment("list_count_version.{$scope}");
    }

    private static function version(string $scope): int
    {
        return (int) FastCache::store()->get("list_count_version.{$scope}", 1);
    }
}
