<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

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
        'hr_manager',
    ];

    /** Spatie roles worth syncing from users.role (excludes generic employee/staff). */
    public const SPATIE_SYNC_ROLES = [
        'admin',
        'procurement_manager',
        'supply_chain_director',
        'logistics_manager',
        'logistics_officer',
        'finance',
        'executive',
        'chairman',
        'hr_manager',
        'vendor',
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
    private const SCM_DEPARTMENT_KEYWORDS = [
        'supply chain',
        'procurement',
        'logistics',
        'finance',
        'executive',
    ];

    /** @var list<string> */
    private const SCM_ROLE_KEYWORDS = [
        'procurement',
        'supply_chain',
        'logistics',
        'finance',
        'executive',
        'chairman',
        'employee',
        'staff',
        'admin',
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
            'procurementmanager' => 'procurement_manager',
            'supplychaindirector' => 'supply_chain_director',
            'logisticsmanager' => 'logistics_manager',
            'logisticsofficer' => 'logistics_officer',
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

    public static function isVendorAccount(User $user): bool
    {
        if (! empty($user->vendor_id)) {
            return true;
        }

        return $user->role === 'vendor'
            || (method_exists($user, 'hasRole') && $user->hasRole('vendor'));
    }

    public static function hasSupplyChainLoginAccess(User $user): bool
    {
        // Main SCM app — vendors must use /api/vendors/auth/login
        if (self::isVendorAccount($user)) {
            return false;
        }

        if (($user->is_admin ?? false) === true) {
            return true;
        }

        if (($user->can_manage_users ?? false) === true) {
            return true;
        }

        if (($user->designated_requisition_creator ?? false) === true) {
            return true;
        }

        foreach (self::candidateRoleKeys($user) as $role) {
            if (in_array($role, self::SCM_LOGIN_ROLES, true)) {
                return true;
            }

            if (self::roleKeywordGrantsAccess($role)) {
                return true;
            }
        }

        if (self::userDepartmentGrantsAccess($user->department)) {
            return true;
        }

        $employee = self::resolveEmployee($user);
        if ($employee !== null) {
            if (self::employeeProfileGrantsAccess($employee)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Infer a canonical users.role from HR profile when the stored role is missing or non-canonical.
     */
    public static function inferCanonicalRoleFromProfile(User $user): ?string
    {
        $employee = self::resolveEmployee($user);
        $title = strtolower(trim((string) ($employee?->job_title ?? '')));
        $department = strtolower(trim((string) ($employee?->department ?? $user->department ?? '')));

        if (str_contains($title, 'chairman') || str_contains($department, 'chairman')) {
            return 'chairman';
        }

        if (str_contains($title, 'executive') || str_contains($department, 'executive')) {
            return 'executive';
        }

        if (str_contains($title, 'procurement') || str_contains($department, 'procurement')) {
            return 'procurement_manager';
        }

        if (str_contains($title, 'supply chain') || str_contains($department, 'supply chain')) {
            return 'supply_chain_director';
        }

        if (str_contains($title, 'logistics officer')) {
            return 'logistics_officer';
        }

        if (str_contains($title, 'logistics') || str_contains($department, 'logistics')) {
            return 'logistics_manager';
        }

        if (str_contains($title, 'finance') || str_contains($department, 'finance')) {
            return 'finance';
        }

        if (self::userDepartmentGrantsAccess($department)) {
            return 'employee';
        }

        return null;
    }

    /**
     * Persist canonical users.role and align Spatie role assignment.
     */
    public static function repairUserAccess(User $user): bool
    {
        $changed = false;
        $canonical = self::normalize($user->role);

        if ($canonical === null || ! self::hasSupplyChainLoginAccess($user)) {
            $inferred = self::inferCanonicalRoleFromProfile($user);
            if ($inferred !== null) {
                $canonical = $inferred;
            }
        }

        if ($canonical !== null && $canonical !== $user->role) {
            $user->role = $canonical;
            $user->save();
            $changed = true;
        }

        if ($canonical !== null) {
            self::syncSpatieRole($user->fresh(), $canonical);
        }

        return $changed;
    }

    public static function syncSpatieRole(User $user, ?string $canonical = null): void
    {
        $canonical ??= self::normalize($user->role);
        if ($canonical === null || ! in_array($canonical, self::SPATIE_SYNC_ROLES, true)) {
            return;
        }

        try {
            Role::firstOrCreate(['name' => $canonical, 'guard_name' => 'web']);

            if (method_exists($user, 'syncRoles') && ! $user->hasRole($canonical)) {
                $user->syncRoles([$canonical]);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not sync Spatie role for user', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $canonical,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function resolveEmployee(User $user): ?Employee
    {
        if (! $user->employee_id) {
            return null;
        }

        if ($user->relationLoaded('employee')) {
            return $user->employee;
        }

        return Employee::query()->find($user->employee_id);
    }

    private static function employeeProfileGrantsAccess(Employee $employee): bool
    {
        $jobTitle = trim((string) ($employee->job_title ?? ''));

        foreach (self::EMPLOYEE_JOB_TITLES as $allowedTitle) {
            if (strcasecmp($jobTitle, $allowedTitle) === 0) {
                return true;
            }
        }

        $titleLower = strtolower($jobTitle);
        foreach (self::SCM_ROLE_KEYWORDS as $keyword) {
            if ($titleLower !== '' && str_contains($titleLower, str_replace('_', ' ', $keyword))) {
                return true;
            }
            if ($titleLower !== '' && str_contains($titleLower, $keyword)) {
                return true;
            }
        }

        if (stripos($jobTitle, 'Finance') !== false) {
            return true;
        }

        return self::userDepartmentGrantsAccess($employee->department);
    }

    private static function userDepartmentGrantsAccess(?string $department): bool
    {
        $department = strtolower(trim((string) $department));
        if ($department === '') {
            return false;
        }

        foreach (self::SCM_DEPARTMENT_KEYWORDS as $keyword) {
            if ($department === $keyword || str_contains($department, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function roleKeywordGrantsAccess(string $role): bool
    {
        foreach (self::SCM_ROLE_KEYWORDS as $keyword) {
            if (str_contains($role, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
