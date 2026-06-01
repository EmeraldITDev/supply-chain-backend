<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Align department labels between settings UI, users.department, and department_codes.
 */
class DepartmentMatcher
{
    public static function normalizeKey(?string $value): string
    {
        $value = Str::of((string) $value)->lower()->trim();
        $value = (string) preg_replace('/[^a-z0-9\s]/', ' ', (string) $value);
        $value = (string) preg_replace('/\s+/', ' ', (string) $value);

        return $value;
    }

    public static function decodeRouteLabel(string $department): string
    {
        return trim(urldecode($department));
    }

    /**
     * Whether a user's department matches the department label from the settings UI / route.
     */
    public static function matches(?string $userDepartment, ?string $requestedDepartment): bool
    {
        $userDepartment = trim((string) $userDepartment);
        $requestedDepartment = trim((string) $requestedDepartment);

        if ($userDepartment === '' || $requestedDepartment === '') {
            return false;
        }

        if (strcasecmp($userDepartment, $requestedDepartment) === 0) {
            return true;
        }

        if (self::normalizeKey($userDepartment) === self::normalizeKey($requestedDepartment)) {
            return true;
        }

        $userRow = self::resolveDepartmentCodeRow($userDepartment);
        $requestedRow = self::resolveDepartmentCodeRow($requestedDepartment);

        if ($userRow !== null && $requestedRow !== null) {
            return (int) $userRow->id === (int) $requestedRow->id
                || strcasecmp((string) $userRow->code, (string) $requestedRow->code) === 0;
        }

        return false;
    }

    /**
     * Resolve settings label to a canonical department_codes.department_name when possible.
     */
    public static function canonicalName(string $label): string
    {
        $row = self::resolveDepartmentCodeRow($label);

        return $row ? (string) $row->department_name : trim($label);
    }

    /**
     * @return object{id: int, department_name: string, code: string}|null
     */
    public static function resolveDepartmentCodeRow(?string $label): ?object
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        $key = self::normalizeKey($label);
        $upper = strtoupper($label);

        $rows = DB::table('department_codes')->select(['id', 'department_name', 'code'])->get();

        foreach ($rows as $row) {
            if (self::normalizeKey($row->department_name) === $key) {
                return $row;
            }
            if (strcasecmp((string) $row->code, $label) === 0 || strtoupper((string) $row->code) === $upper) {
                return $row;
            }
            if (self::normalizeKey($row->code) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public static function matchingUserIds(string $departmentLabel): array
    {
        $departmentLabel = trim($departmentLabel);
        if ($departmentLabel === '') {
            return [];
        }

        return \App\Models\User::query()
            ->get(['id', 'department'])
            ->filter(fn (\App\Models\User $user) => self::matches($user->department, $departmentLabel))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
