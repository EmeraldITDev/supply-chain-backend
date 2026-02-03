<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('role:admin,logistics_manager')
     */
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED'
            ], 401);
        }

        $roleList = array_map('trim', explode(',', $roles));
        $hasRole = (isset($user->role) && in_array($user->role, $roleList, true)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($roleList));

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        return $next($request);
    }
}
