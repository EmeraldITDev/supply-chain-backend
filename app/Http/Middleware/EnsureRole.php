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
            $route = $request->route();
            $routeAction = $route ? ($route->getActionName() ?? null) : null;
            $routeName = $route ? ($route->getName() ?? null) : null;
            $routeUri = $route ? $route->uri() : null;
            $routeMiddleware = $route ? $route->gatherMiddleware() : [];

            Log::warning('EnsureRole denying request', [
                'path' => $request->path(),
                'user_id' => $user->id ?? null,
                'user_role_column' => $user->role ?? null,
                'user_role_keys' => $userRoleKeys,
                'required_role_list' => $roleList,
                'raw_roles_param' => $roles,
                'route_uri' => $routeUri,
                'route_action' => $routeAction,
                'route_name' => $routeName,
                'route_middleware' => $routeMiddleware,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
                'debug' => [
                    'user_role_column' => $user->role ?? null,
                    'user_role_keys' => $userRoleKeys,
                    'required_role_list' => $roleList,
                    'raw_roles_param' => $roles,
                    'route_uri' => $routeUri,
                    'route_action' => $routeAction,
                    'route_middleware' => $routeMiddleware,
                ],
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
