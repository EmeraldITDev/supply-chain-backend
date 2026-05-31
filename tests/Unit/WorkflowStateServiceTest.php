<?php

namespace Tests\Unit;

use App\Services\WorkflowStateMapper;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Tests\TestCase;

class WorkflowStateServiceTest extends TestCase
{
    public function test_po_signed_can_transition_to_finance_handoff_pending(): void
    {
        $service = app(WorkflowStateService::class);

        $this->assertTrue(
            $service->canTransition(
                WorkflowStateService::STATE_PO_SIGNED,
                WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING
            )
        );
    }

    public function test_delivery_confirmation_flow_transitions(): void
    {
        $service = app(WorkflowStateService::class);

        $this->assertTrue($service->canTransition(
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING
        ));

        $this->assertTrue($service->canTransition(
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE
        ));

        $this->assertTrue($service->canTransition(
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING
        ));
    }

    public function test_workflow_state_mapper_maps_po_signed_legacy_fields(): void
    {
        $mapper = app(WorkflowStateMapper::class);

        $legacy = $mapper->legacyFieldsFor(WorkflowStateService::STATE_PO_SIGNED);

        $this->assertSame('signed', $legacy['status']);
        $this->assertSame('finance', $legacy['current_stage']);
    }
}
