<?php

namespace Tests\Unit;

use App\Services\Finance\FinanceApOpenPurchaseOrderService;
use App\Services\WorkflowStateService;
use Tests\TestCase;

class FinanceApOpenPurchaseOrderServiceTest extends TestCase
{
    public function test_payable_workflow_states_include_issued_and_partial_delivery(): void
    {
        $states = FinanceApOpenPurchaseOrderService::PAYABLE_WORKFLOW_STATES;

        $this->assertContains(WorkflowStateService::STATE_PO_SIGNED, $states);
        $this->assertContains(WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING, $states);
        $this->assertContains(WorkflowStateService::STATE_FINANCE_IN_REVIEW, $states);
    }
}
