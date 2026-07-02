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
        $this->assertTrue(DepartmentMatcher::matches('IT', 'IT'));
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

    public function test_matches_ict_to_it(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('ICT', 'IT'));
        $this->assertSame('IT', DepartmentMatcher::normalizeToStandardDepartment('ICT'));
    }

    public function test_unique_department_labels_collapses_it_aliases(): void
    {
        $labels = DepartmentMatcher::uniqueDepartmentLabels([
            'IT',
            'ICT',
            'it',
        ]);

        $this->assertCount(1, $labels);
        $this->assertSame('IT', $labels[0]);
    }

    public function test_normalize_legacy_logistics_to_supply_chain(): void
    {
        $this->assertSame('Supply Chain', DepartmentMatcher::normalizeToStandardDepartment('Logistics'));
    }

    public function test_standard_user_departments_list(): void
    {
        $this->assertContains('IT', DepartmentMatcher::standardUserDepartments());
        $this->assertContains('Technical Operations', DepartmentMatcher::standardUserDepartments());
        $this->assertCount(9, DepartmentMatcher::standardUserDepartments());
    }
}
