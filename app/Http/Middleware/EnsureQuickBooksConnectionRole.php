<?php

namespace App\Http\Middleware;

use App\Support\QuickBooksRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connection management (connect / disconnect / inspect) uses an exact role match.
 * EnsureRole expands procurement → procurement_manager; that must not grant Connect.
 */
class EnsureQuickBooksConnectionRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (! QuickBooksRoles::userCanManageConnection($user)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
