<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profiles per-request SQL usage and logs slow queries / heavy endpoints.
 */
class LogSlowQueries
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.log_slow_queries', false)) {
            return $next($request);
        }

        $queries = [];
        $thresholdMs = (int) config('performance.slow_query_ms', 200);

        DB::listen(function ($query) use (&$queries, $thresholdMs, $request): void {
            $timeMs = (float) $query->time;
            $queries[] = $timeMs;

            if ($timeMs >= $thresholdMs) {
                Log::warning('Slow query detected', [
                    'duration_ms' => $timeMs,
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]);
            }
        });

        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $queryCount = count($queries);
        $queryTimeMs = round(array_sum($queries), 2);

        $requestThreshold = (int) config('performance.slow_request_ms', 1000);
        $maxQueries = (int) config('performance.max_queries_per_request', 50);

        if ($durationMs >= $requestThreshold || $queryCount > $maxQueries) {
            Log::warning('Slow API request', [
                'path' => $request->path(),
                'method' => $request->method(),
                'duration_ms' => $durationMs,
                'query_count' => $queryCount,
                'query_time_ms' => $queryTimeMs,
            ]);
        }

        if (config('performance.expose_query_headers', false)) {
            $response->headers->set('X-Query-Count', (string) $queryCount);
            $response->headers->set('X-Query-Time-Ms', (string) $queryTimeMs);
            $response->headers->set('X-Response-Time-Ms', (string) $durationMs);
        }

        return $response;
    }
}
