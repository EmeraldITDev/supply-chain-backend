<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\User;
use App\Notifications\MRFApprovedNotification;
use App\Notifications\MRFRejectedNotification;
use App\Notifications\MRFSubmittedNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MrfEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_notifies_only_the_next_parallel_approvers(): void
    {
        Notification::fake();

        $executive = User::factory()->create([
            'email' => 'executive@example.com',
            'supply_chain_role' => 'executive',
        ]);
        $director = User::factory()->create([
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        $procurement = User::factory()->create([
            'email' => 'procurement@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);

        app(NotificationService::class)->notifyMRFSubmitted($this->mrf([
            'workflow_state' => 'parallel_first_approval',
        ]));

        Notification::assertSentToTimes($executive, MRFSubmittedNotification::class, 1);
        Notification::assertSentToTimes($director, MRFSubmittedNotification::class, 1);
        Notification::assertNotSentTo($procurement, MRFSubmittedNotification::class);
    }

    public function test_submission_deduplicates_users_with_the_same_normalized_email(): void
    {
        Notification::fake();

        $firstRole = User::factory()->create([
            'email' => 'approver@example.com',
            'supply_chain_role' => 'executive',
        ]);
        $secondRole = User::factory()->create([
            'email' => 'APPROVER@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        app(NotificationService::class)->notifyMRFSubmitted($this->mrf([
            'workflow_state' => 'parallel_first_approval',
        ]));

        Notification::assertSentToTimes($firstRole, MRFSubmittedNotification::class, 1);
        Notification::assertNotSentTo($secondRole, MRFSubmittedNotification::class);
    }

    public function test_fast_tracked_submission_does_not_send_an_approval_notification(): void
    {
        Notification::fake();

        $approver = User::factory()->create([
            'email' => 'approver@example.com',
            'supply_chain_role' => 'executive',
        ]);

        app(NotificationService::class)->notifyMRFSubmitted($this->mrf([
            'source' => 'po_generated',
        ]));

        Notification::assertNotSentTo($approver, MRFSubmittedNotification::class);
    }

    public function test_approval_sends_one_notification_with_database_and_mail_channels(): void
    {
        Notification::fake();

        $requester = User::factory()->create(['email' => 'requester@example.com']);
        $approver = User::factory()->create(['name' => 'Approver']);

        app(NotificationService::class)->notifyMRFApproved(
            $this->mrfWithRequester($requester),
            $approver,
            'Approved for procurement',
        );

        Notification::assertSentToTimes($requester, MRFApprovedNotification::class, 1);
        Notification::assertSentTo($requester, MRFApprovedNotification::class, function ($notification, $channels) use ($requester) {
            return in_array('mail', $channels, true)
                && in_array('database', $channels, true)
                && str_contains($notification->toArray($requester)['remarks'], 'Approved for procurement');
        });
    }

    public function test_rejection_sends_one_notification_and_preserves_the_reason(): void
    {
        Notification::fake();

        $requester = User::factory()->create(['email' => 'requester@example.com']);
        $rejector = User::factory()->create(['name' => 'Rejector']);

        app(NotificationService::class)->notifyMRFRejected(
            $this->mrfWithRequester($requester),
            $rejector,
            'Budget justification is incomplete',
        );

        Notification::assertSentToTimes($requester, MRFRejectedNotification::class, 1);
        Notification::assertSentTo($requester, MRFRejectedNotification::class, function ($notification, $channels) use ($requester) {
            return in_array('mail', $channels, true)
                && in_array('database', $channels, true)
                && $notification->toArray($requester)['reason'] === 'Budget justification is incomplete';
        });
    }

    public function test_intermediate_submission_routes_to_procurement_not_previous_approvers(): void
    {
        Notification::fake();

        $procurement = User::factory()->create([
            'email' => 'procurement@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);
        $executive = User::factory()->create([
            'email' => 'executive@example.com',
            'supply_chain_role' => 'executive',
        ]);
        $director = User::factory()->create([
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        app(NotificationService::class)->notifyMRFSubmitted($this->mrf([
            'workflow_state' => 'procurement_review',
        ]));

        Notification::assertSentToTimes($procurement, MRFSubmittedNotification::class, 1);
        Notification::assertNotSentTo($executive, MRFSubmittedNotification::class);
        Notification::assertNotSentTo($director, MRFSubmittedNotification::class);
    }

    public function test_explicit_mrf_resubmission_notifies_the_new_parallel_approvers(): void
    {
        Notification::fake();

        $requester = User::factory()->create(['email' => 'resubmitter@example.com']);
        $executive = User::factory()->create([
            'email' => 'resubmit-executive@example.com',
            'supply_chain_role' => 'executive',
        ]);
        $director = User::factory()->create([
            'email' => 'resubmit-director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        $mrf = MRF::create([
            'mrf_id' => 'MRF-RESUBMIT-001',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'title' => 'Resubmission',
            'category' => 'Operations',
            'description' => 'Corrected request',
            'quantity' => '1',
            'estimated_cost' => 100,
            'justification' => 'Required correction',
            'date' => now()->toDateString(),
            'status' => 'rejected',
            'workflow_state' => 'rejected',
            'current_stage' => 'rejected',
        ]);

        Sanctum::actingAs($requester);

        $this->postJson('/api/mrfs/'.$mrf->mrf_id.'/resubmit', [
            'title' => 'Corrected resubmission',
        ])->assertOk();

        Notification::assertSentToTimes($executive, MRFSubmittedNotification::class, 1);
        Notification::assertSentToTimes($director, MRFSubmittedNotification::class, 1);
    }

    private function mrf(array $attributes = []): MRF
    {
        return new MRF(array_merge([
            'mrf_id' => 'MRF-TEST-001',
            'title' => 'Test MRF',
            'requester_name' => 'Requester',
            'workflow_state' => 'parallel_first_approval',
            'current_stage' => 'parallel_first_approval',
            'source' => 'standard',
        ], $attributes));
    }

    private function mrfWithRequester(User $requester): MRF
    {
        $mrf = $this->mrf([
            'requester_id' => $requester->id,
        ]);
        $mrf->setRelation('requester', $requester);

        return $mrf;
    }
}
