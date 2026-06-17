<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\ProcurementOverviewAccess;
use Tests\TestCase;

class ProcurementOverviewAccessTest extends TestCase
{
    public function test_logistics_manager_is_procurement_overview_only(): void
    {
        $user = new User(['supply_chain_role' => 'logistics_manager']);

        $this->assertTrue(ProcurementOverviewAccess::isProcurementOverviewOnly($user));
        $this->assertTrue(ProcurementOverviewAccess::canAccessProcurementPage($user));
    }

    public function test_procurement_manager_can_manage_procurement(): void
    {
        $user = new User(['supply_chain_role' => 'procurement_manager']);

        $this->assertFalse(ProcurementOverviewAccess::isProcurementOverviewOnly($user));
        $this->assertTrue(ProcurementOverviewAccess::canAccessProcurementPage($user));
    }
}
