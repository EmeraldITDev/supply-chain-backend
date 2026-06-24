<?php

namespace Tests\Unit;

use App\Support\DepartmentMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DepartmentCodesSeeder::class);
    }
    public function test_matches_case_insensitive_department_names(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('Human Resources', 'HUMAN RESOURCES'));
    }

    public function test_matches_department_code_to_full_name(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('Information Technology', 'IT'));
        $this->assertTrue(DepartmentMatcher::matches('Human Resources', 'HR'));
    }

    public function test_matches_case_insensitive_finance_department(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('finance', 'Finance'));
        $this->assertTrue(DepartmentMatcher::matches('FINANCE', 'finance'));
    }

    public function test_storage_label_normalizes_finance_casing(): void
    {
        $this->assertSame('Finance', DepartmentMatcher::storageLabel('finance'));
        $this->assertSame('Finance', DepartmentMatcher::storageLabel('FINANCE'));
    }

    public function test_rejects_unrelated_departments(): void
    {
        $this->assertFalse(DepartmentMatcher::matches('Finance', 'Human Resources'));
    }

    public function test_matches_ict_to_information_technology(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('ICT', 'Information Technology'));
        $this->assertTrue(DepartmentMatcher::matches('ICT', 'IT'));
    }

    public function test_unique_department_labels_collapses_ict_aliases(): void
    {
        DB::table('department_codes')->insert([
            'department_name' => 'ICT',
            'code' => 'ICT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $labels = DepartmentMatcher::uniqueDepartmentLabels([
            'Information Technology',
            'ICT',
            'IT',
        ]);

        $this->assertCount(1, $labels);
        $this->assertSame('Information Technology', $labels[0]);
    }
}
