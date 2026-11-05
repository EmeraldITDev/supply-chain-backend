<?php

namespace Tests\Feature;

use App\Mail\TripRequestDirectorApprovedMail;
use App\Mail\TripRequestForwardedMail;
use App\Mail\TripRequestRejectedMail;
use App\Mail\TripRequestSubmittedMail;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use App\Services\Logistics\TripRequestNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Stage5TripNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_submission_notifies_only_logistics_next_approvers_and_deduplicates_email(): void
    {
        Mail::fake();
        Notification::fake();

        $requester = User::factory()->create();
        $logistics = User::factory()->create(['supply_chain_role' => 'logistics_manager']);
        User::factory()->create(['supply_chain_role' => 'supply_chain_director']);
        User::factory()->create(['supply_chain_role' => 'logistics_officer', 'email' => $logistics->email]);
        $trip = $this->trip($requester);

        $this->service()->notifySubmitted($trip, $requester);

        Mail::assertSent(TripRequestSubmittedMail::class, 1);
        Mail::assertSent(TripRequestSubmittedMail::class, fn ($mail) => $mail->hasTo($logistics->email));
        Notification::assertSentTo($logistics, LogisticsEventNotification::class);
    }

    public function test_approval_and_rejection_email_only_the_requester_once(): void
    {
        Mail::fake();
        Notification::fake();

        $requester = User::factory()->create();
        $director = User::factory()->create(['supply_chain_role' => 'supply_chain_director']);
        $trip = $this->trip($requester);
        $trip->setRelation('creator', $requester);

        $this->service()->notifyDirectorApproved($trip, $director);
        Mail::assertSent(TripRequestDirectorApprovedMail::class, 1);
        Mail::assertSent(TripRequestDirectorApprovedMail::class, fn ($mail) => $mail->hasTo($requester->email));
        Notification::assertSentTo($requester, LogisticsEventNotification::class);

        Mail::fake();
        Notification::fake();
        $this->service()->notifyRejected($trip, $requester, 'Budget declined');
        Mail::assertSent(TripRequestRejectedMail::class, 1);
        Mail::assertSent(TripRequestRejectedMail::class, fn ($mail) => $mail->hasTo($requester->email));
        Notification::assertSentTo($requester, LogisticsEventNotification::class);
    }

    public function test_routine_trip_comment_remains_in_app_only(): void
    {
        Mail::fake();
        Notification::fake();

        $requester = User::factory()->create();
        $author = User::factory()->create();
        $trip = $this->trip($requester);
        $trip->passenger_user_ids = [$author->id];

        $this->service()->notifyComment($trip, $author, 'Status update', true);

        Mail::assertNothingSent();
        Notification::assertSentTo($requester, LogisticsEventNotification::class);
    }

    private function service(): TripRequestNotificationService
    {
        return app(TripRequestNotificationService::class);
    }

    private function trip(User $requester): Trip
    {
        return new Trip([
            'id' => 501,
            'trip_code' => 'TRQ-STAGE5',
            'created_by' => $requester->id,
            'origin' => 'Abuja',
            'destination' => 'Lagos',
            'purpose' => 'Site visit',
            'scheduled_departure_at' => now()->addDay(),
        ]);
    }
}
