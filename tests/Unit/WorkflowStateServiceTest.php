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

    public function test_closure_transition_blocked_when_readiness_fails(): void
    {
        $this->mock(\App\Services\FinanceAp\ClosureReadinessService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn([
                'financially_complete' => false,
                'operationally_complete' => false,
                'can_close' => false,
                'blockers' => ['Not all payment milestones are marked paid or complete.'],
                'milestoneSummary' => [],
            ]);
        });

        $service = app(WorkflowStateService::class);
        $mrf = new \App\Models\MRF([
            'mrf_id' => 'MRF-TEST-001',
            'workflow_state' => WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
        ]);

        $this->assertTrue($service->canTransition(
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_CLOSED
        ));

        $result = $service->applyWorkflowState(
            $mrf,
            WorkflowStateService::STATE_CLOSED,
            new \App\Models\User(['id' => 1])
        );

        $this->assertFalse($result);
    }
}
