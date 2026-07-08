<?php

namespace Tests\Unit;

use App\Models\Logistics\Trip;
use App\Support\TripDisplayStatus;
use Tests\TestCase;

class TripDisplayStatusTest extends TestCase
{
    public function test_changes_requested_workflow_maps_to_display_status(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-20260708-TEST',
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_CHANGES_REQUESTED,
        ]);

        $this->assertSame('changes_requested', TripDisplayStatus::resolve($trip));
        $this->assertSame('Changes Requested', TripDisplayStatus::label('changes_requested'));
    }

    public function test_director_review_maps_to_under_review(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-20260708-TEST',
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_DIRECTOR_REVIEW,
        ]);

        $this->assertSame('under_review', TripDisplayStatus::resolve($trip));
        $this->assertSame('Under Review', TripDisplayStatus::label('under_review'));
    }

    public function test_revision_required_maps_from_approval_status(): void
    {
        $trip = new Trip([
            'trip_code' => 'TRQ-20260708-TEST',
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_CHANGES_REQUESTED,
            'approval_status' => 'revision_required',
        ]);

        $this->assertSame('revision_required', TripDisplayStatus::resolve($trip));
        $this->assertSame('Revision Required', TripDisplayStatus::label('revision_required'));
    }
}
