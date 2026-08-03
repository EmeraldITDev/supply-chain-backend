<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_scd_actions_are_driven_by_the_api_contract(): void
    {
        $trip = new Trip([
            'workflow_stage' => Trip::WORKFLOW_SCD_REVIEW,
            'approval_status' => 'submitted',
        ]);

        $this->assertSame(['scd_approve', 'scd_reject'], $trip->availableScdActions());
    }

    public function test_requires_scd_approval_is_only_true_when_stage_is_scd_review_and_not_already_approved(): void
    {
        $trip = new Trip([
            'workflow_stage' => Trip::WORKFLOW_SCD_REVIEW,
            'approval_status' => 'submitted',
        ]);

        $this->assertTrue($trip->requiresScdApproval());

        $approved = new Trip([
            'workflow_stage' => Trip::WORKFLOW_SCD_REVIEW,
            'approval_status' => 'approved',
        ]);

        $this->assertFalse($approved->requiresScdApproval());
    }
}
