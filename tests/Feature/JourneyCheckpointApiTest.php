<?php

namespace Tests\Feature;

use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JourneyCheckpointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_checkpoint_updates_journey_metadata_and_last_checkpoint_fields(): void
    {
        $trip = Trip::create([
            'trip_code' => 'TRIP-TEST-001',
            'title' => 'Test trip',
            'origin' => 'Nairobi',
            'destination' => 'Kisumu',
            'status' => Trip::STATUS_SCHEDULED,
        ]);

        $journey = Journey::create([
            'trip_id' => $trip->id,
            'trip_code' => $trip->trip_code,
            'title' => 'Journey 1',
            'origin' => 'Nairobi',
            'destination' => 'Kisumu',
            'status' => Journey::STATUS_NOT_STARTED,
            'metadata' => [],
        ]);

        $user = User::factory()->create([ 'supply_chain_role' => 'logistics_manager' ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/journeys/' . $journey->id . '/checkpoints', [
            'location' => 'Kisumu checkpoint',
            'notes' => 'Reached checkpoint',
            'timestamp' => '2026-08-08 10:00:00',
        ]);

        $response->assertOk();

        $journey->refresh();
        $this->assertSame('Kisumu checkpoint', $journey->last_checkpoint_location);
        $this->assertNotNull($journey->last_checkpoint_at);
        $this->assertSame('Reached checkpoint', $journey->metadata['last_checkpoint_notes'] ?? null);
        $this->assertSame('Reached checkpoint', $journey->metadata['notes'] ?? null);
        $this->assertSame('Reached checkpoint', $journey->metadata['checkpoints'][0]['notes'] ?? null);
    }
}
