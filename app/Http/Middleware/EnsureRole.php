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
        $roleList = array_values(array_unique($roleList));

        // Legacy DB value `logistics` is treated like `logistics_manager` everywhere else (AuthController, PermissionService).
        if (in_array('logistics_manager', $roleList, true) && !in_array('logistics', $roleList, true)) {
            $roleList[] = 'logistics';
        }

        $userRoleKeys = $this->collectUserRoleKeys($user);
        $hasRole = count(array_intersect($userRoleKeys, $roleList)) > 0;

        if (!$hasRole && method_exists($user, 'hasAnyRole')) {
            $hasRole = $user->hasAnyRole($roleList);
        }

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function collectUserRoleKeys($user): array
    {
        $keys = [];

        if (!empty($user->role)) {
            $keys[] = $user->role;
            $normalized = $this->normalizeRoleKey($user->role);
            if ($normalized !== null && $normalized !== $user->role) {
                $keys[] = $normalized;
            }
        }

        if (method_exists($user, 'getRoleNames')) {
            foreach ($user->getRoleNames() as $name) {
                $keys[] = (string) $name;
                $normalized = $this->normalizeRoleKey((string) $name);
                if ($normalized !== null) {
                    $keys[] = $normalized;
                }
            }
        }

        return array_values(array_unique(array_filter($keys, static fn ($v) => $v !== '')));
    }

    private function normalizeRoleKey(string $role): ?string
    {
        $trimmed = trim($role);
        if ($trimmed === '') {
            return null;
        }

        $key = strtolower(str_replace([' ', '-'], ['_', '_'], $trimmed));

        return match ($key) {
            'logistics' => 'logistics_manager',
            'procurement' => 'procurement_manager',
            'supply_chain' => 'supply_chain_director',
            'finance_officer' => 'finance',
            default => $key,
        };
    }
}
