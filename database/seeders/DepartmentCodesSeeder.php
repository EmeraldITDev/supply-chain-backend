<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentCodesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
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

        foreach ($rows as $row) {
            DB::table('department_codes')->updateOrInsert(
                ['department_name' => $row['department_name']],
                ['code' => $row['code'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
