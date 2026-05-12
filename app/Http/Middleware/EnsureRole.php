<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('role:admin,logistics_manager')
     *
     * Laravel passes the comma-separated list as VARIADIC arguments
     * (`role:a,b,c` → `handle($req, $next, 'a', 'b', 'c')`). The previous
     * signature accepted only `string $roles`, so PHP silently bound only
     * the first role and discarded the rest, causing every multi-role
     * `role:` middleware to behave as `role:<firstRoleOnly>`.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED'
            ], 401);
        }

        // Support both forms: variadic (`role:a,b,c`) and legacy single-string
        // (`role:a|b|c` or callers passing a pre-joined CSV).
        $flattened = [];
        foreach ($roles as $segment) {
            foreach (explode(',', (string) $segment) as $part) {
                $flattened[] = trim($part);
            }
        }
        $roleList = array_values(array_unique(array_filter($flattened, static fn ($v) => $v !== '')));

        // Legacy DB value `logistics` is treated like `logistics_manager` everywhere else (AuthController, PermissionService).
        if (in_array('logistics_manager', $roleList, true) && !in_array('logistics', $roleList, true)) {
            $roleList[] = 'logistics';
        }

        $userRoleKeys = $this->collectUserRoleKeys($user);
        $hasRole = count(array_intersect($userRoleKeys, $roleList)) > 0;

        if (!$hasRole && method_exists($user, 'hasAnyRole')) {
            try {
                $hasRole = $user->hasAnyRole($roleList);
            } catch (\Throwable $e) {
                Log::warning('EnsureRole hasAnyRole threw', [
                    'user_id' => $user->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$hasRole) {
            Log::warning('EnsureRole denying request', [
                'path' => $request->path(),
                'user_id' => $user->id ?? null,
                'user_role_column' => $user->role ?? null,
                'user_role_keys' => $userRoleKeys,
                'required_role_list' => $roleList,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
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
