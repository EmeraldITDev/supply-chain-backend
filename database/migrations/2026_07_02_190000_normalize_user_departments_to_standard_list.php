<?php

use App\Support\DepartmentMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize user/employee departments to the fixed SCM user-management list.
 *
 * Legacy → standard mapping (closest match):
 * - Information Technology, ICT → IT
 * - Human Resources, HR → Human Resources
 * - Finance, FIN → Finance
 * - Operations, OPS, Administration → Operations
 * - Logistics, LOG, Supply Chain, SC → Supply Chain
 * - Engineering, ENG, Technical Operations → Technical Operations
 * - Marketing, MKT, Legal, LEG → Business Development
 * - Procurement, PRC → Procurement
 * - Executive, EXE → Executive
 * - Business Development, BD → Business Development
 * - Unknown / empty after normalization → Operations (default)
 */
return new class extends Migration
{
    private const DEPARTMENT_CODE_ROWS = [
        ['department_name' => 'Business Development', 'code' => 'BD'],
        ['department_name' => 'Operations', 'code' => 'OPS'],
        ['department_name' => 'Finance', 'code' => 'FIN'],
        ['department_name' => 'IT', 'code' => 'IT'],
        ['department_name' => 'Human Resources', 'code' => 'HR'],
        ['department_name' => 'Procurement', 'code' => 'PRC'],
        ['department_name' => 'Executive', 'code' => 'EXE'],
        ['department_name' => 'Supply Chain', 'code' => 'SC'],
        ['department_name' => 'Technical Operations', 'code' => 'TEO'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('department_codes')) {
            $keepNames = array_column(self::DEPARTMENT_CODE_ROWS, 'department_name');
            DB::table('department_codes')
                ->whereNotIn('department_name', $keepNames)
                ->delete();

            foreach (self::DEPARTMENT_CODE_ROWS as $row) {
                DB::table('department_codes')->updateOrInsert(
                    ['department_name' => $row['department_name']],
                    [
                        'code' => $row['code'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        $this->normalizeUserDepartments();
        $this->normalizeEmployeeDepartments();
    }

    public function down(): void
    {
        // Non-reversible: legacy department spellings are not restored.
    }

    private function normalizeUserDepartments(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'department')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $mapped = DepartmentMatcher::normalizeToStandardDepartment((string) $user->department)
                        ?? 'Operations';

                    if ($mapped !== $user->department) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['department' => $mapped]);
                    }
                }
            });
    }

    private function normalizeEmployeeDepartments(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'department')) {
            return;
        }

        DB::table('employees')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    $mapped = DepartmentMatcher::normalizeToStandardDepartment((string) $employee->department)
                        ?? 'Operations';

                    if ($mapped !== $employee->department) {
                        DB::table('employees')
                            ->where('id', $employee->id)
                            ->update(['department' => $mapped]);
                    }
                }
            });
    }
};
