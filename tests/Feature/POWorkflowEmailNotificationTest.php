<?php

namespace Tests\Feature;

use App\Jobs\ProcessPurchaseOrderNotificationsJob;
use App\Mail\POGeneratedMail;
use App\Mail\POReadyForSignatureMail;
use App\Mail\POSignedMail;
use App\Mail\VendorSelectionApprovedMail;
use App\Models\MRF;
use App\Models\User;
use App\Services\ManualVendorOnboardingService;
use App\Services\NotificationService;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class POWorkflowEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_track_po_generation_still_emails_signers_and_stakeholders(): void
    {
        Mail::fake();

        $director = User::factory()->create([
            'email' => 'director@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        $requester = User::factory()->create([
            'email' => 'requester@example.com',
        ]);
        $generator = User::factory()->create([
            'email' => 'procurement@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-PO-EMAIL-001',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'title' => 'Fast track PO',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => 'po_generated',
            'po_number' => 'PO-EMAIL-001',
        ]);

        $job = new ProcessPurchaseOrderNotificationsJob(
            $mrf->id,
            $generator->id,
            'PO-EMAIL-001',
            true,
            false,
            false,
        );

        $onboarding = $this->createMock(ManualVendorOnboardingService::class);
        $onboarding->expects($this->once())->method('finalizeVendorsForPoGeneration');

        $job->handle(
            app(NotificationService::class),
            app(WorkflowNotificationService::class),
            $onboarding,
        );

        Mail::assertSent(POGeneratedMail::class, function (POGeneratedMail $mail) use ($requester) {
            return $mail->hasTo($requester->email);
        });
        Mail::assertSent(POReadyForSignatureMail::class, function (POReadyForSignatureMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });
    }

    public function test_signed_po_emails_finance_requester_and_vendor(): void
    {
        Mail::fake();

        $finance = User::factory()->create([
            'email' => 'finance@example.com',
            'supply_chain_role' => 'finance',
        ]);
        $requester = User::factory()->create([
            'email' => 'requester-signed@example.com',
        ]);
        $vendor = \App\Models\Vendor::create([
            'name' => 'Signed Vendor',
            'email' => 'vendor-signed@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-PO-EMAIL-002',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'title' => 'Signed PO',
            'status' => 'po_signed',
            'workflow_state' => 'po_signed',
            'po_number' => 'PO-EMAIL-002',
            'selected_vendor_id' => $vendor->id,
            'po_signed_at' => now(),
        ]);

        app(NotificationService::class)->notifyPOSignedToFinance($mrf);

        foreach ([$finance->email, $requester->email, $vendor->email] as $email) {
            Mail::assertSent(POSignedMail::class, function (POSignedMail $mail) use ($email) {
                return $mail->hasTo($email);
            });
        }
    }

    public function test_vendor_selection_approval_emails_procurement(): void
    {
        Mail::fake();

        $procurement = User::factory()->create([
            'email' => 'buyer@example.com',
            'supply_chain_role' => 'procurement_manager',
        ]);
        $approver = User::factory()->create([
            'email' => 'director-approve@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-PO-EMAIL-003',
            'requester_name' => 'Requester',
            'title' => 'Approved vendor',
            'status' => 'pending_po_upload',
            'workflow_state' => 'invoice_approved',
        ]);

        app(WorkflowNotificationService::class)->notifyVendorSelectionApproved($mrf, $approver);

        Mail::assertSent(VendorSelectionApprovedMail::class, function (VendorSelectionApprovedMail $mail) use ($procurement) {
            return $mail->hasTo($procurement->email);
        });
    }
}
