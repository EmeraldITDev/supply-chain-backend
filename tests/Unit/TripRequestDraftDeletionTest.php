<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Logistics\TripRequestWorkflowController;
use App\Models\Logistics\Trip;
use ReflectionMethod;
use Tests\TestCase;

class TripRequestDraftDeletionTest extends TestCase
{
    public function test_draft_trip_request_is_deletable(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-20260601-TEST',
            'status' => Trip::STATUS_DRAFT,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
        ]);

        $method = new ReflectionMethod(TripRequestWorkflowController::class, 'isDeletableDraft');
        $method->setAccessible(true);
        $controller = app(TripRequestWorkflowController::class);

        $this->assertTrue($method->invoke($controller, $trip));
    }

    public function test_submitted_trip_request_is_not_deletable(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-20260601-TEST',
            'status' => Trip::STATUS_SCHEDULED,
            'workflow_stage' => Trip::WORKFLOW_PROCUREMENT_REVIEW,
        ]);

        $method = new ReflectionMethod(TripRequestWorkflowController::class, 'isDeletableDraft');
        $method->setAccessible(true);
        $controller = app(TripRequestWorkflowController::class);

        $this->assertFalse($method->invoke($controller, $trip));
    }
}
