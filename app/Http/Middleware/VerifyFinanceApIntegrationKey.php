<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFinanceApIntegrationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('finance_ap.integration_inbound_key');

        if (! $expected) {
            return response()->json([
                'success' => false,
                'error' => 'Finance AP integration is not configured',
                'code' => 'INTEGRATION_DISABLED',
            ], 503);
        }

        $provided = $request->header('X-Api-Key') ?? $request->bearerToken();

        if (! $provided || ! hash_equals((string) $expected, (string) $provided)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid integration API key',
                'code' => 'UNAUTHORIZED',
            ], 401);
        }

        return $next($request);
    }
}
