<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccommodationBookingAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_accommodation_booking_can_upload_multiple_attachments_to_s3(): void
    {
        Storage::fake('s3');
        config(['filesystems.documents_disk' => 's3']);

        $user = User::factory()->create([ 'supply_chain_role' => 'logistics_manager' ]);
        Sanctum::actingAs($user);

        $trip = Trip::create([
            'trip_code' => 'TRIP-TEST-ATTACH',
            'title' => 'Attachment trip',
            'origin' => 'Nairobi',
            'destination' => 'Mombasa',
            'status' => Trip::STATUS_SCHEDULED,
        ]);

        $fileA = UploadedFile::fake()->create('hotel-confirmation.pdf', 100, 'application/pdf');
        $fileB = UploadedFile::fake()->create('invoice.jpg', 75, 'image/jpeg');

        $response = $this->post('/api/logistics/accommodations', [
            'trip_id' => $trip->id,
            'passenger_names' => ['Jane Doe', 'John Smith'],
            'destination_state' => 'Coastal',
            'destination_city' => 'Mombasa',
            'number_of_nights' => 3,
            'hotel_name' => 'Sample Hotel',
            'check_in_date' => now()->addDay()->format('Y-m-d'),
            'attachments' => [$fileA, $fileB],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.booking.attachments', fn ($attachments) => count($attachments) === 2);

        $bookingId = $response->json('data.booking.id');
        $this->assertNotNull($bookingId);

        $attachments = Attachment::where('attachable_type', \App\Models\Logistics\AccommodationBooking::class)
            ->where('attachable_id', $bookingId)
            ->get();

        $this->assertCount(2, $attachments);

        foreach ($attachments as $attachment) {
            Storage::disk('s3')->assertExists($attachment->file_path);
        }
    }
}
