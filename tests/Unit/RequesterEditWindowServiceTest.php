<?php

namespace Tests\Unit;

use App\Models\Logistics\Trip;
use App\Models\MRF;
use App\Models\SRF;
use App\Models\User;
use App\Services\RequesterEditWindowService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RequesterEditWindowServiceTest extends TestCase
{
    private RequesterEditWindowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RequesterEditWindowService::class);
    }

    public function test_requester_can_edit_own_mrf_within_window(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 5, 'department' => 'Finance']);
        $mrf = new MRF([
            'requester_id' => 5,
            'department' => 'Finance',
            'status' => 'Pending',
            'workflow_state' => WorkflowStateService::STATE_EXECUTIVE_REVIEW,
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $this->assertTrue($this->service->canRequesterEditMrf($user, $mrf));
    }

    public function test_designated_department_creator_can_edit_department_mrf(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User([
            'id' => 9,
            'department' => 'Finance',
            'designated_requisition_creator' => true,
        ]);
        $mrf = new MRF([
            'requester_id' => 5,
            'department' => 'Finance',
            'status' => 'Pending',
            'workflow_state' => WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW,
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $this->assertTrue($this->service->canRequesterEditMrf($user, $mrf));
    }

    public function test_mrf_edit_denied_after_window_expires(): void
    {
        Carbon::setTestNow('2026-06-26T10:00:00Z');

        $user = new User(['id' => 5, 'department' => 'Finance']);
        $mrf = new MRF([
            'requester_id' => 5,
            'department' => 'Finance',
            'status' => 'Pending',
            'workflow_state' => WorkflowStateService::STATE_EXECUTIVE_REVIEW,
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $result = $this->service->evaluateMrfEdit($user, $mrf);

        $this->assertFalse($result['allowed']);
        $this->assertSame(RequesterEditWindowService::CODE_WINDOW_EXPIRED, $result['code']);
    }

    public function test_rejected_mrf_is_workflow_locked_for_in_place_edit(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 5]);
        $mrf = new MRF([
            'requester_id' => 5,
            'status' => 'Rejected',
            'workflow_state' => WorkflowStateService::STATE_EXECUTIVE_REJECTED,
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $result = $this->service->evaluateMrfEdit($user, $mrf);

        $this->assertFalse($result['allowed']);
        $this->assertSame(RequesterEditWindowService::CODE_WORKFLOW_LOCKED, $result['code']);
    }

    public function test_po_generated_mrf_is_workflow_locked(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 5]);
        $mrf = new MRF([
            'requester_id' => 5,
            'status' => 'awaiting_scd_signature',
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'po_generated_at' => Carbon::parse('2026-06-24T08:00:00Z'),
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $this->assertFalse($this->service->canRequesterEditMrf($user, $mrf));
    }

    public function test_trip_creator_can_edit_submitted_request_before_confirmation(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 3]);
        $trip = new Trip([
            'trip_code' => 'TRQ-20260623-ABC',
            'created_by' => 3,
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'metadata' => [],
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $this->assertTrue($this->service->canRequesterEditTrip($user, $trip));
    }

    public function test_confirmed_trip_request_is_workflow_locked(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 3]);
        $trip = new Trip([
            'trip_code' => 'TRQ-20260623-ABC',
            'created_by' => 3,
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_LOGISTICS_REVIEW,
            'metadata' => ['logistics_trip_id' => 99],
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $result = $this->service->evaluateTripEdit($user, $trip);

        $this->assertFalse($result['allowed']);
        $this->assertSame(RequesterEditWindowService::CODE_WORKFLOW_LOCKED, $result['code']);
    }

    public function test_srf_rejected_status_is_workflow_locked(): void
    {
        Carbon::setTestNow('2026-06-24T10:00:00Z');

        $user = new User(['id' => 2]);
        $srf = new SRF([
            'requester_id' => 2,
            'status' => 'Rejected',
            'current_stage' => 'rejected',
            'created_at' => Carbon::parse('2026-06-23T12:00:00Z'),
        ]);

        $this->assertFalse($this->service->canRequesterEditSrf($user, $srf));
    }

    public function test_meta_includes_expiry_timestamp(): void
    {
        $createdAt = Carbon::parse('2026-06-24T14:30:00Z');
        $user = new User(['id' => 1]);

        $mrf = new MRF([
            'requester_id' => 1,
            'status' => 'Pending',
            'workflow_state' => WorkflowStateService::STATE_EXECUTIVE_REVIEW,
            'created_at' => $createdAt,
        ]);

        $meta = $this->service->metaForMrf($user, $mrf);

        $this->assertArrayHasKey('can_requester_edit', $meta);
        $this->assertArrayHasKey('requester_edit_expires_at', $meta);
        $this->assertSame('2026-06-26T14:30:00+00:00', $meta['requester_edit_expires_at']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
