<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Align department labels between settings UI, users.department, and department_codes.
 */
class DepartmentMatcher
{
    /**
     * Canonical departments for user management (create / edit forms).
     *
     * @var list<string>
     */
    public const STANDARD_USER_DEPARTMENTS = [
        'Business Development',
        'Operations',
        'Finance',
        'IT',
        'Human Resources',
        'Procurement',
        'Executive',
        'Supply Chain',
        'Technical Operations',
    ];

    /**
     * Legacy / alias labels mapped to a standard department (normalized keys).
     *
     * @var array<string, string>
     */
    private const LEGACY_DEPARTMENT_ALIASES = [
        'business development' => 'Business Development',
        'bd' => 'Business Development',
        'operations' => 'Operations',
        'ops' => 'Operations',
        'finance' => 'Finance',
        'fin' => 'Finance',
        'it' => 'IT',
        'ict' => 'IT',
        'information technology' => 'IT',
        'human resources' => 'Human Resources',
        'hr' => 'Human Resources',
        'procurement' => 'Procurement',
        'prc' => 'Procurement',
        'executive' => 'Executive',
        'exe' => 'Executive',
        'supply chain' => 'Supply Chain',
        'sc' => 'Supply Chain',
        'logistics' => 'Supply Chain',
        'log' => 'Supply Chain',
        'technical operations' => 'Technical Operations',
        'technical' => 'Technical Operations',
        'teo' => 'Technical Operations',
        'engineering' => 'Technical Operations',
        'eng' => 'Technical Operations',
        'administration' => 'Operations',
        'adm' => 'Operations',
        'marketing' => 'Business Development',
        'mkt' => 'Business Development',
        'legal' => 'Business Development',
        'leg' => 'Business Development',
    ];

    /**
     * @return list<string>
     */
    public static function standardUserDepartments(): array
    {
        return self::STANDARD_USER_DEPARTMENTS;
    }

    /**
     * Map a legacy or alias label to a standard user-management department, if possible.
     */
    public static function normalizeToStandardDepartment(?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        foreach (self::STANDARD_USER_DEPARTMENTS as $standard) {
            if (strcasecmp($standard, $label) === 0) {
                return $standard;
            }
        }

        $key = self::normalizeKey($label);
        if (isset(self::LEGACY_DEPARTMENT_ALIASES[$key])) {
            return self::LEGACY_DEPARTMENT_ALIASES[$key];
        }

        $row = self::resolveDepartmentCodeRow($label);
        if ($row !== null) {
            $fromCode = self::normalizeToStandardDepartment((string) $row->department_name);
            if ($fromCode !== null) {
                return $fromCode;
            }
            $fromCodeKey = self::normalizeToStandardDepartment((string) $row->code);
            if ($fromCodeKey !== null) {
                return $fromCodeKey;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function standardDepartmentsForValidation(): array
    {
        return self::STANDARD_USER_DEPARTMENTS;
    }

    /**
     * Department labels that refer to the same org unit (normalized keys per group).
     *
     * @var list<list<string>>
     */
    private const SYNONYM_GROUPS = [
        ['ict', 'it', 'information technology'],
        ['hr', 'human resources'],
        ['fin', 'finance'],
        ['ops', 'operations', 'administration', 'adm'],
        ['log', 'logistics', 'sc', 'supply chain'],
        ['prc', 'procurement'],
        ['exe', 'executive'],
        ['bd', 'business development', 'marketing', 'mkt', 'legal', 'leg'],
        ['teo', 'technical operations', 'engineering', 'eng', 'technical'],
    ];

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
     * Canonical label for persisting a department on users/MRFs so "finance" and
     * "Finance" resolve to the same stored value when possible.
     */
    public static function storageLabel(?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        $standard = self::normalizeToStandardDepartment($label);
        if ($standard !== null) {
            return $standard;
        }

        $row = self::resolveDepartmentCodeRow($label);
        if ($row !== null) {
            $fromRow = self::normalizeToStandardDepartment((string) $row->department_name);
            if ($fromRow !== null) {
                return $fromRow;
            }

            return (string) $row->department_name;
        }

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
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
        $standard = self::normalizeToStandardDepartment($label);
        if ($standard !== null) {
            return $standard;
        }

        $row = self::resolveDepartmentCodeRow($label);

        return $row ? (string) $row->department_name : trim($label);
    }

    /**
     * Stable key for collapsing department aliases in list UIs.
     */
    public static function dedupeKey(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }

        $synonymGroup = self::synonymGroupId($label);
        if ($synonymGroup !== null) {
            return 'synonym:' . $synonymGroup;
        }

        $row = self::resolveDepartmentCodeRow($label);
        if ($row !== null) {
            return 'dept_code:' . (int) $row->id;
        }

        return 'dept_name:' . self::normalizeKey($label);
    }

    /**
     * @param iterable<string> $labels
     * @return list<string>
     */
    public static function uniqueDepartmentLabels(iterable $labels): array
    {
        $grouped = [];

        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $key = self::dedupeKey($label);
            if ($key === '' || isset($grouped[$key])) {
                continue;
            }

            $grouped[$key] = self::canonicalName($label);
        }

        $values = array_values($grouped);
        sort($values);

        return $values;
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
        $matches = [];

        foreach ($rows as $row) {
            if (self::normalizeKey($row->department_name) === $key) {
                $matches[(int) $row->id] = $row;
            }
            if (strcasecmp((string) $row->code, $label) === 0 || strtoupper((string) $row->code) === $upper) {
                $matches[(int) $row->id] = $row;
            }
            if (self::normalizeKey($row->code) === $key) {
                $matches[(int) $row->id] = $row;
            }
        }

        $synonymGroup = self::synonymGroupId($label);
        if ($synonymGroup !== null) {
            foreach ($rows as $row) {
                if (self::synonymGroupId($row->department_name) === $synonymGroup
                    || self::synonymGroupId($row->code) === $synonymGroup) {
                    $matches[(int) $row->id] = $row;
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        return self::pickCanonicalDepartmentRow(array_values($matches));
    }

    /**
     * @param list<object{id: int, department_name: string, code: string}> $rows
     */
    private static function pickCanonicalDepartmentRow(array $rows): object
    {
        usort($rows, function (object $a, object $b): int {
            $nameCompare = strlen((string) $b->department_name) <=> strlen((string) $a->department_name);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return (int) $a->id <=> (int) $b->id;
        });

        return $rows[0];
    }

    private static function synonymGroupId(?string $label): ?string
    {
        $key = self::normalizeKey($label);
        if ($key === '') {
            return null;
        }

        foreach (self::SYNONYM_GROUPS as $group) {
            if (in_array($key, $group, true)) {
                return implode('|', $group);
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
