<?php

namespace Tests\Feature;

use App\Mail\POReadyForSignatureMail;
use App\Models\MRF;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class POGeneratedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_chain_director_receives_only_the_po_signature_email(): void
    {
        Mail::fake();
        Notification::fake();

        $director = User::factory()->create([
            'name' => 'Supply Chain Director',
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        User::factory()->create([
            'email' => 'other-director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        $requester = User::factory()->create([
            'email' => 'requester@example.com',
        ]);

        $vendor = \App\Models\Vendor::create([
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-TEST-001',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'title' => 'Test MRF',
            'status' => 'approved',
            'workflow_state' => 'po_generated',
            'po_number' => 'PO-TEST-001',
            'selected_vendor_id' => $vendor->id,
            'director_approved_by' => $director->id,
        ]);

        app(\App\Services\WorkflowNotificationService::class)->notifyPOGenerated($mrf);

        Mail::assertSent(POReadyForSignatureMail::class, function (POReadyForSignatureMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });
        Mail::assertNotSent(POReadyForSignatureMail::class, function (POReadyForSignatureMail $mail) {
            return $mail->hasTo('other-director@example.com');
        });
        Mail::assertNotSent(\App\Mail\POGeneratedMail::class);
        Notification::assertSentToTimes($director, \App\Notifications\SystemAnnouncementNotification::class, 1);
    }

    public function test_duplicate_role_accounts_with_the_same_email_receive_one_notification(): void
    {
        Mail::fake();
        Notification::fake();

        $director = User::factory()->create([
            'email' => 'SIGNATORY@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        User::factory()->create([
            'email' => 'signatory@example.com',
            'supply_chain_role' => 'admin',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-TEST-002',
            'requester_name' => 'Requester',
            'title' => 'Duplicate recipient test',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => 'po_generated',
            'po_number' => 'PO-TEST-002',
        ]);

        app(\App\Services\WorkflowNotificationService::class)->notifyPOReadyForSignature($mrf);

        Mail::assertSent(POReadyForSignatureMail::class, fn (POReadyForSignatureMail $mail) => $mail->hasTo('SIGNATORY@example.com'));
        Mail::assertSentCount(1);
        Notification::assertSentToTimes($director, \App\Notifications\SystemAnnouncementNotification::class, 1);
    }
}
