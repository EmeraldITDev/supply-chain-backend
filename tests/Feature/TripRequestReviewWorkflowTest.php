<?php

namespace Tests\Feature;

use App\Mail\TripRequestForwardedMail;
use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripRequestReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_requester_can_create_trip_request_with_accommodation_and_escort_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Requester',
            'email' => 'requester@example.com',
            'supply_chain_role' => 'employee',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/trip-requests', [
            'destination' => 'Lagos',
            'purpose' => 'Site visit',
            'scheduled_departure_at' => now()->addDays(3)->toDateTimeString(),
            'scheduled_arrival_at' => now()->addDays(3)->addHours(2)->toDateTimeString(),
            'origin' => 'Abuja',
            'passenger_user_ids' => [$user->id],
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'accommodation_required' => true,
            'accommodation_name' => 'Hotel A',
            'accommodation_address' => '10 Marina Road',
            'accommodation_contact' => '08012345678',
            'accommodation_details' => 'Near the office',
            'accommodation_estimated_cost' => 5000000,
            'escort_required' => true,
            'escort_description' => 'Armed guard',
        ]);

        $response->assertCreated()
            ->assertJsonPath('trip.accommodation_required', true)
            ->assertJsonPath('trip.accommodation_name', 'Hotel A')
            ->assertJsonPath('trip.escort_required', true)
            ->assertJsonPath('trip.escort_description', 'Armed guard');

        $this->assertDatabaseHas('logistics_trips', [
            'created_by' => $user->id,
            'accommodation_required' => true,
            'accommodation_name' => 'Hotel A',
            'escort_required' => true,
            'escort_description' => 'Armed guard',
        ]);
    }

    public function test_draft_trip_request_remains_private_until_explicit_submit(): void
    {
        $user = User::factory()->create([
            'name' => 'Requester',
            'email' => 'requester-draft@example.com',
            'supply_chain_role' => 'employee',
        ]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/trip-requests', [
            'destination' => 'Abuja',
            'purpose' => 'Planning visit',
            'scheduled_departure_at' => now()->addDays(5)->toDateTimeString(),
            'scheduled_arrival_at' => now()->addDays(5)->addHours(2)->toDateTimeString(),
            'origin' => 'Lagos',
            'passenger_user_ids' => [$user->id],
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'accommodation_required' => false,
            'escort_required' => false,
            'save_as_draft' => true,
        ]);

        $createResponse->assertCreated();
        $tripId = $createResponse->json('trip.id');

        $this->assertSame(Trip::STATUS_DRAFT, $createResponse->json('trip.status'));

        $submitResponse = $this->postJson('/api/trip-requests/' . $tripId . '/submit');
        $submitResponse->assertOk();
        $this->assertSame(Trip::STATUS_SUBMITTED, $submitResponse->json('data.status'));

        $this->assertDatabaseHas('logistics_trips', [
            'id' => $tripId,
            'status' => Trip::STATUS_SUBMITTED,
            'created_by' => $user->id,
        ]);
    }

    public function test_logistics_prefixed_submit_endpoint_promotes_draft_to_submitted(): void
    {
        $user = User::factory()->create([
            'name' => 'Requester',
            'email' => 'requester-logistics-submit@example.com',
            'supply_chain_role' => 'employee',
        ]);

        $trip = Trip::create([
            'trip_code' => 'TRQ-20260721-TEST2',
            'title' => 'Trip request: Abuja',
            'purpose' => 'Planning visit',
            'origin' => 'Lagos',
            'destination' => 'Abuja',
            'scheduled_departure_at' => now()->addDays(5),
            'scheduled_arrival_at' => now()->addDays(5)->addHours(2),
            'passenger_user_ids' => [$user->id],
            'status' => Trip::STATUS_DRAFT,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'draft',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'created_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logistics/trip-requests/' . $trip->id . '/submit');

        $response->assertOk()
            ->assertJsonPath('data.status', Trip::STATUS_SUBMITTED);

        $this->assertSame(Trip::STATUS_SUBMITTED, $trip->fresh()->status);
    }

    public function test_logistics_manager_can_update_accommodation_and_escort_details_with_audit_log(): void
    {
        $user = User::factory()->create([
            'name' => 'Logistics Manager',
            'email' => 'logistics@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);

        $trip = Trip::create([
            'trip_code' => 'TRQ-20260721-TEST1',
            'title' => 'Trip request: Lagos',
            'purpose' => 'Site visit',
            'origin' => 'Abuja',
            'destination' => 'Lagos',
            'scheduled_departure_at' => now()->addDays(3),
            'scheduled_arrival_at' => now()->addDays(3)->addHours(2),
            'passenger_user_ids' => [$user->id],
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'submitted',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'accommodation_required' => true,
            'accommodation_name' => 'Hotel A',
            'accommodation_estimated_cost' => 5000000,
            'escort_required' => true,
            'escort_description' => 'Police escort',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/trip-requests/' . $trip->id . '/logistics-review', [
            'accommodation_name' => 'Hotel B',
            'accommodation_estimated_cost' => 3200000,
            'escort_description' => 'Armed guard',
            'comments' => 'Approved for budget review',
            'reason' => 'Hotel A exceeds approved budget',
            'action' => 'forward',
        ]);

        $response->assertOk()
            ->assertJsonPath('trip.accommodation_name', 'Hotel B')
            ->assertJsonPath('trip.accommodation_estimated_cost', 3200000)
            ->assertJsonPath('trip.escort_description', 'Armed guard');

        $this->assertDatabaseHas('trip_request_edits', [
            'trip_request_id' => $trip->id,
            'field_name' => 'accommodation_name',
            'edited_by' => $user->id,
        ]);

        $this->assertNotEmpty($response->json('trip.audit_trail'));
    }

    public function test_logistics_conversion_requires_an_allowed_workflow_state(): void
    {
        $logisticsManager = User::factory()->create([
            'name' => 'Logistics Manager',
            'email' => 'logistics-convert@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);

        $trip = Trip::create([
            'trip_code' => 'TRQ-20260804-CONVERT',
            'title' => 'Trip request: Abuja',
            'purpose' => 'Planning visit',
            'origin' => 'Lagos',
            'destination' => 'Abuja',
            'scheduled_departure_at' => now()->addDays(4),
            'scheduled_arrival_at' => now()->addDays(4)->addHours(2),
            'passenger_user_ids' => [$logisticsManager->id],
            'status' => Trip::STATUS_DRAFT,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'draft',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'created_by' => $logisticsManager->id,
        ]);

        Sanctum::actingAs($logisticsManager);

        $invalidResponse = $this->postJson('/api/trips/' . $trip->id . '/convert-to-logistics-request', []);

        $invalidResponse->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE');

        $trip->update([
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'submitted',
        ]);

        $validResponse = $this->postJson('/api/trips/' . $trip->id . '/convert-to-logistics-request', [
            'driver' => [
                'driver_type' => 'existing',
                'driver_id' => $logisticsManager->id,
            ],
        ]);

        $validResponse->assertOk();
        $this->assertSame(Trip::WORKFLOW_LOGISTICS_PROCESSING, $trip->fresh()->workflow_stage);
        $this->assertSame(Trip::STATUS_SCHEDULED, $trip->fresh()->status);
    }

    public function test_converted_trip_requests_are_hidden_from_active_directory_lists(): void
    {
        $logisticsManager = User::factory()->create([
            'name' => 'Logistics Manager',
            'email' => 'logistics-directory@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);

        $trip = Trip::create([
            'trip_code' => 'TRQ-20260804-DIR',
            'title' => 'Trip request: Abuja',
            'purpose' => 'Planning visit',
            'origin' => 'Lagos',
            'destination' => 'Abuja',
            'scheduled_departure_at' => now()->addDays(4),
            'scheduled_arrival_at' => now()->addDays(4)->addHours(2),
            'passenger_user_ids' => [$logisticsManager->id],
            'status' => Trip::STATUS_CONVERTED,
            'workflow_stage' => Trip::WORKFLOW_CONVERTED_TO_LOGISTICS_REQUEST,
            'approval_status' => 'converted',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'created_by' => $logisticsManager->id,
        ]);

        Sanctum::actingAs($logisticsManager);

        $response = $this->getJson('/api/trips?scope=requests');

        $response->assertOk();
        $this->assertSame(0, collect($response->json('trips'))->filter(fn ($item) => ($item['id'] ?? null) === $trip->id)->count());
    }

    public function test_forwarding_trip_request_sends_email_to_supply_chain_director(): void
    {
        Mail::fake();

        $logisticsManager = User::factory()->create([
            'name' => 'Logistics Manager',
            'email' => 'logistics@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);

        $director = User::factory()->create([
            'name' => 'SCD',
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        $trip = Trip::create([
            'trip_code' => 'TRQ-20260721-TEST2',
            'title' => 'Trip request: Lagos',
            'purpose' => 'Site visit',
            'origin' => 'Abuja',
            'destination' => 'Lagos',
            'scheduled_departure_at' => now()->addDays(3),
            'scheduled_arrival_at' => now()->addDays(3)->addHours(2),
            'passenger_user_ids' => [$logisticsManager->id],
            'status' => Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'submitted',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => Trip::BOOKING_SCOPE_WITHIN_STATE,
            'accommodation_required' => true,
            'accommodation_name' => 'Hotel A',
            'accommodation_estimated_cost' => 5000000,
            'escort_required' => true,
            'escort_description' => 'Police escort',
        ]);

        Sanctum::actingAs($logisticsManager);

        $response = $this->postJson('/api/trip-requests/' . $trip->id . '/logistics-review', [
            'accommodation_name' => 'Hotel B',
            'accommodation_estimated_cost' => 3200000,
            'escort_description' => 'Armed guard',
            'comments' => 'Approved for budget review',
            'reason' => 'Hotel A exceeds approved budget',
            'action' => 'forward',
        ]);

        $response->assertOk();

        Mail::assertSent(TripRequestForwardedMail::class, function (TripRequestForwardedMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });
    }
}
