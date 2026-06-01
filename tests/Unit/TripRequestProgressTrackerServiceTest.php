<?php

namespace Tests\Unit;

use App\Models\Logistics\Trip;
use App\Services\Logistics\TripRequestProgressTrackerService;
use Tests\TestCase;

class TripRequestProgressTrackerServiceTest extends TestCase
{
    public function test_new_trip_request_is_at_submitted_step(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-TEST',
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'status' => Trip::STATUS_DRAFT,
        ]);

        $payload = app(TripRequestProgressTrackerService::class)->build($trip);

        $this->assertSame('submitted', $payload['currentStepKey']);
        $this->assertSame('in_progress', collect($payload['steps'])->firstWhere('key', 'submitted')['status']);
    }

    public function test_po_signed_maps_to_completed(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-TEST',
            'workflow_stage' => Trip::WORKFLOW_PO_SIGNED,
            'status' => Trip::STATUS_CLOSED,
        ]);

        $payload = app(TripRequestProgressTrackerService::class)->build($trip);

        $this->assertSame('completed', $payload['currentStepKey']);
        $this->assertSame('completed', collect($payload['steps'])->last()['status']);
    }
}
