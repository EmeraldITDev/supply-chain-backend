<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;

/**
 * Canonical role keys and SCM login access checks.
 * Aligns with UserManagementController, EnsureRole middleware, and PermissionService.
 */
class UserRoleNormalizer
{
    /** @var list<string> */
    public const SCM_LOGIN_ROLES = [
        'procurement_manager',
        'procurement',
        'supply_chain_director',
        'supply_chain',
        'logistics_manager',
        'logistics_officer',
        'logistics',
        'finance',
        'finance_officer',
        'executive',
        'chairman',
        'employee',
        'staff',
        'regular_staff',
        'admin',
    ];

    /** @var list<string> */
    private const EMPLOYEE_JOB_TITLES = [
        'Procurement Manager',
        'Executive',
        'Chairman',
        'Supply Chain Director',
        'Logistics Manager',
        'Logistics Officer',
    ];

    /** @var list<string> */
    private const EMPLOYEE_DEPARTMENTS = [
        'supply chain',
        'procurement',
        'logistics',
        'finance',
    ];

    public static function normalize(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

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

    /**
     * @return list<string>
     */
    public static function candidateRoleKeys(User $user): array
    {
        $keys = [];

        if (! empty($user->role)) {
            $normalized = self::normalize((string) $user->role);
            if ($normalized !== null) {
                $keys[] = $normalized;
            }
        }

        if (method_exists($user, 'getRoleNames')) {
            try {
                foreach ($user->getRoleNames() as $name) {
                    $normalized = self::normalize((string) $name);
                    if ($normalized !== null) {
                        $keys[] = $normalized;
                    }
                }
            } catch (\Throwable) {
                // Spatie guard / cache issues — rely on users.role only
            }
        }

        return array_values(array_unique($keys));
    }

    public static function hasSupplyChainLoginAccess(User $user): bool
    {
        if ($user->role === 'vendor' || (method_exists($user, 'hasRole') && $user->hasRole('vendor'))) {
            return true;
        }

        if (($user->is_admin ?? false) === true) {
            return true;
        }

        foreach (self::candidateRoleKeys($user) as $role) {
            if (in_array($role, self::SCM_LOGIN_ROLES, true)) {
                return true;
            }
        }

        if (! $user->employee_id) {
            return false;
        }

        $employee = $user->relationLoaded('employee')
            ? $user->employee
            : Employee::query()->find($user->employee_id);

        if (! $employee) {
            return false;
        }

        $jobTitle = trim((string) ($employee->job_title ?? ''));

        foreach (self::EMPLOYEE_JOB_TITLES as $allowedTitle) {
            if (strcasecmp($jobTitle, $allowedTitle) === 0) {
                return true;
            }
        }

        if (stripos($jobTitle, 'Finance') !== false) {
            return true;
        }

        $department = strtolower(trim((string) ($employee->department ?? '')));

        foreach (self::EMPLOYEE_DEPARTMENTS as $allowedDept) {
            if ($department === $allowedDept || str_contains($department, $allowedDept)) {
                return true;
            }
        }

        return false;
    }
}
