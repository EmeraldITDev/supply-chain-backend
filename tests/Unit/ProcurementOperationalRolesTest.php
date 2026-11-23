<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PurchaseOrderRevisionService;
use App\Services\WorkflowStateService;
use App\Support\ProcurementOperationalRoles;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcurementOperationalRolesTest extends TestCase
{
    #[Test]
    public function procurement_manager_and_supply_chain_director_share_operational_access(): void
    {
        $pm = new User(['supply_chain_role' => 'procurement_manager']);
        $scd = new User(['supply_chain_role' => 'supply_chain_director']);
        $scdAlias = new User(['supply_chain_role' => 'supply_chain']);
        $finance = new User(['supply_chain_role' => 'finance']);

        $this->assertTrue(ProcurementOperationalRoles::allows($pm));
        $this->assertTrue(ProcurementOperationalRoles::allows($scd));
        $this->assertTrue(ProcurementOperationalRoles::allows($scdAlias));
        $this->assertFalse(ProcurementOperationalRoles::allows($finance));

        $this->assertTrue(ProcurementOperationalRoles::allowsForceClose($pm));
        $this->assertTrue(ProcurementOperationalRoles::allowsForceClose($scd));
        // Legacy `procurement` alias normalizes to procurement_manager
        $this->assertTrue(ProcurementOperationalRoles::allowsForceClose(
            new User(['supply_chain_role' => 'procurement'])
        ));
        $this->assertFalse(ProcurementOperationalRoles::allowsForceClose($finance));
    }

    #[Test]
    public function permission_service_treats_scd_as_procurement_operator_without_granting_finance_actions(): void
    {
        $permissions = app(PermissionService::class);

        $pm = new User(['supply_chain_role' => 'procurement_manager']);
        $scd = new User(['supply_chain_role' => 'supply_chain_director']);
        $finance = new User(['supply_chain_role' => 'finance']);

        $this->assertTrue($permissions->userActsAsProcurement($pm));
        $this->assertTrue($permissions->userActsAsProcurement($scd));
        $this->assertFalse($permissions->userActsAsProcurement($finance));

        $this->assertTrue($permissions->canCreateMRF($pm));
        $this->assertTrue($permissions->canCreateMRF($scd));

        $mrf = new MRF([
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'status' => 'procurement_review',
            'contract_type' => 'emerald',
        ]);

        $this->assertTrue($permissions->canSelectVendors($pm, $mrf));
        $this->assertTrue($permissions->canSelectVendors($scd, $mrf));
        $this->assertFalse($permissions->canSelectVendors($finance, $mrf));

        // Director-only invoice approval stays SCD, not PM
        $vendorSelected = new MRF([
            'workflow_state' => WorkflowStateService::STATE_VENDOR_SELECTED,
            'status' => 'vendor_selected',
        ]);
        $this->assertFalse($permissions->canApproveInvoice($pm, $vendorSelected));
        $this->assertTrue($permissions->canApproveInvoice($scd, $vendorSelected));

        // Force-close capability parity for manager + SCD
        $forceCloseMrf = new MRF([
            'workflow_state' => WorkflowStateService::STATE_PAYMENT_PROCESSED,
            'status' => 'paid',
        ]);
        $this->assertTrue($permissions->canForceClose($pm, $forceCloseMrf));
        $this->assertTrue($permissions->canForceClose($scd, $forceCloseMrf));
        $this->assertFalse($permissions->canForceClose($finance, $forceCloseMrf));
    }

    #[Test]
    public function scd_can_unlock_signed_purchase_orders_like_procurement_manager(): void
    {
        $this->assertTrue(PurchaseOrderRevisionService::userCanRevise(
            new User(['supply_chain_role' => 'procurement_manager'])
        ));
        $this->assertTrue(PurchaseOrderRevisionService::userCanRevise(
            new User(['supply_chain_role' => 'supply_chain_director'])
        ));
        $this->assertFalse(PurchaseOrderRevisionService::userCanRevise(
            new User(['supply_chain_role' => 'executive'])
        ));
    }

    #[Test]
    public function dashboard_flags_give_scd_generate_po_while_keeping_review_authority(): void
    {
        $permissions = app(PermissionService::class);

        $pmFlags = $permissions->getDashboardData(new User(['supply_chain_role' => 'procurement_manager']));
        $scdFlags = $permissions->getDashboardData(new User(['supply_chain_role' => 'supply_chain_director']));

        $this->assertTrue($pmFlags['can_generate_po']);
        $this->assertTrue($scdFlags['can_generate_po']);
        $this->assertTrue($scdFlags['can_review_po']);
        $this->assertTrue($scdFlags['can_approve']);
        $this->assertFalse($pmFlags['can_approve']);
    }
}
