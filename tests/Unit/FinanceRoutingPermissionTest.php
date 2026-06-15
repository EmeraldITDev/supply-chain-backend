<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Tests\TestCase;

class FinanceRoutingPermissionTest extends TestCase
{
    public function test_finance_cannot_process_payment_for_post_cutover_mrf(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        $user = new User(['supply_chain_role' => 'finance']);
        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-06-01'),
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
            'status' => 'finance',
        ]);

        $permissions = app(PermissionService::class);

        $this->assertFalse($permissions->canProcessPayment($user, $mrf));
        $this->assertTrue($permissions->canViewFinanceSync($user, $mrf));
    }

    public function test_finance_can_process_payment_for_pre_cutover_mrf_in_finance_status(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $user = new User(['supply_chain_role' => 'finance']);
        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-05-01'),
            'status' => 'finance',
            'current_stage' => 'finance',
        ]);

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->canProcessPayment($user, $mrf));
        $this->assertFalse($permissions->canViewFinanceSync($user, $mrf));
    }
}
