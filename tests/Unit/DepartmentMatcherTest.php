<?php

namespace Tests\Unit;

use App\Support\DepartmentMatcher;
use Tests\TestCase;

class DepartmentMatcherTest extends TestCase
{
    public function test_matches_case_insensitive_department_names(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('Human Resources', 'HUMAN RESOURCES'));
    }

    public function test_matches_department_code_to_full_name(): void
    {
        $this->assertTrue(DepartmentMatcher::matches('Information Technology', 'IT'));
        $this->assertTrue(DepartmentMatcher::matches('Human Resources', 'HR'));
    }

    public function test_rejects_unrelated_departments(): void
    {
        $this->assertFalse(DepartmentMatcher::matches('Finance', 'Human Resources'));
    }
}
