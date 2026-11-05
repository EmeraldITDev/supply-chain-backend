<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\SRF;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Stage34NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_grn_request_notifies_only_the_designated_receiving_officer(): void
    {
        Notification::fake();

        $receivingOfficer = User::factory()->create([
            'email' => 'receiving@example.com',
            'supply_chain_role' => 'logistics_officer',
        ]);
        $procurement = User::factory()->create([
            'email' => 'procurement@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'supply_chain_role' => 'admin',
        ]);
        $requester = User::factory()->create([
            'email' => 'requester@example.com',
        ]);

        $mrf = new MRF([
            'mrf_id' => 'MRF-GRN-001',
            'po_number' => 'PO-GRN-001',
        ]);

        app(NotificationService::class)->notifyGRNRequested($mrf, $requester);

        Notification::assertSentToTimes($receivingOfficer, \App\Notifications\SystemAnnouncementNotification::class, 1);
        Notification::assertNotSentTo($procurement, \App\Notifications\SystemAnnouncementNotification::class);
        Notification::assertNotSentTo($admin, \App\Notifications\SystemAnnouncementNotification::class);
    }

    public function test_repeated_grn_request_notification_is_suppressed(): void
    {
        $receivingOfficer = User::factory()->create([
            'email' => 'receiving-repeat@example.com',
            'supply_chain_role' => 'logistics_officer',
        ]);
        $requester = User::factory()->create([
            'email' => 'requester-repeat@example.com',
        ]);
        $mrf = MRF::create([
            'mrf_id' => 'MRF-GRN-002',
            'po_number' => 'PO-GRN-002',
        ]);

        $service = app(NotificationService::class);
        $service->notifyGRNRequested($mrf, $requester);
        $service->notifyGRNRequested($mrf, $requester);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $receivingOfficer->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_ambiguous_receiving_officers_receive_no_grn_notification(): void
    {
        Notification::fake();

        User::factory()->create([
            'email' => 'receiving-one@example.com',
            'supply_chain_role' => 'logistics_officer',
        ]);
        User::factory()->create([
            'email' => 'receiving-two@example.com',
            'supply_chain_role' => 'logistics_officer',
        ]);
        $requester = User::factory()->create([
            'email' => 'requester-ambiguous@example.com',
        ]);

        app(NotificationService::class)->notifyGRNRequested(new MRF([
            'mrf_id' => 'MRF-GRN-AMBIGUOUS',
            'po_number' => 'PO-GRN-AMBIGUOUS',
        ]), $requester);

        Notification::assertNothingSent();
    }

    public function test_non_actionable_mrf_and_srf_edits_do_not_dispatch_notifications(): void
    {
        Notification::fake();

        $editor = User::factory()->create([
            'email' => 'editor@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);
        $director = User::factory()->create([
            'email' => 'director-edit@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        $service = app(NotificationService::class);
        $service->notifyMRFRequesterUpdated(new MRF(['mrf_id' => 'MRF-EDIT-001']), $editor, 'Description');
        $service->notifySRFRequesterUpdated(new SRF(['srf_id' => 'SRF-EDIT-001']), $editor, 'Description');

        Notification::assertNotSentTo($editor, \App\Notifications\MRFRequesterUpdatedNotification::class);
        Notification::assertNotSentTo($editor, \App\Notifications\SRFRequesterUpdatedNotification::class);
        Notification::assertNotSentTo($director, \App\Notifications\MRFRequesterUpdatedNotification::class);
        Notification::assertNotSentTo($director, \App\Notifications\SRFRequesterUpdatedNotification::class);
    }
}
