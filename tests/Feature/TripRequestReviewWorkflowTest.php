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
