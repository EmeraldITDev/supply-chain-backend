<?php

namespace Tests\Feature;

use App\Mail\SRFCreatedMail;
use App\Models\SRF;
use App\Models\User;
use App\Notifications\SRFSubmittedNotification;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SrfSubmissionNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_srf_submission_notifies_only_logistics_next_step_roles(): void
    {
        Mail::fake();
        Notification::fake();

        $logistics = User::factory()->create([
            'email' => 'logistics@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);
        $procurement = User::factory()->create([
            'email' => 'procurement@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);
        $admin = User::factory()->create([
            'email' => 'it@example.com',
            'supply_chain_role' => 'admin',
        ]);

        app(WorkflowNotificationService::class)->notifySRFSubmitted($this->srf([
            'current_stage' => 'logistics_review',
        ]));

        Mail::assertSent(SRFCreatedMail::class, fn ($mail) => $mail->hasTo('logistics@example.com'));
        Mail::assertNotSent(SRFCreatedMail::class, fn ($mail) => $mail->hasTo('procurement@example.com'));
        Mail::assertNotSent(SRFCreatedMail::class, fn ($mail) => $mail->hasTo('it@example.com'));

        Notification::assertSentTo($logistics, SRFSubmittedNotification::class);
        Notification::assertNotSentTo($procurement, SRFSubmittedNotification::class);
        Notification::assertNotSentTo($admin, SRFSubmittedNotification::class);
    }

    public function test_director_stage_srf_notifies_supply_chain_directors_only(): void
    {
        Mail::fake();
        Notification::fake();

        $director = User::factory()->create([
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        $logistics = User::factory()->create([
            'email' => 'logistics@example.com',
            'supply_chain_role' => 'logistics_manager',
        ]);

        app(WorkflowNotificationService::class)->notifySRFSubmitted($this->srf([
            'current_stage' => 'supply_chain_director_review',
        ]));

        Mail::assertSent(SRFCreatedMail::class, fn ($mail) => $mail->hasTo('director@example.com'));
        Mail::assertNotSent(SRFCreatedMail::class, fn ($mail) => $mail->hasTo('logistics@example.com'));
        Notification::assertSentTo($director, SRFSubmittedNotification::class);
        Notification::assertNotSentTo($logistics, SRFSubmittedNotification::class);
    }

    private function srf(array $overrides = []): SRF
    {
        return SRF::create(array_merge([
            'srf_id' => 'SRF-NOTIF-'.uniqid(),
            'formatted_id' => 'SRF/NOTIF/'.uniqid(),
            'title' => 'Notification recipient test',
            'service_type' => 'Maintenance',
            'urgency' => 'Normal',
            'description' => 'Test SRF',
            'requester_name' => 'Requester',
            'department' => 'Operations',
            'date' => now()->toDateString(),
            'status' => 'submitted',
            'current_stage' => 'logistics_review',
        ], $overrides));
    }
}
