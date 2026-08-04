<?php

namespace Tests\Feature;

use App\Mail\POGeneratedMail;
use App\Models\MRF;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class POGeneratedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_chain_director_receives_po_generated_email(): void
    {
        Mail::fake();

        $director = User::factory()->create([
            'name' => 'Supply Chain Director',
            'email' => 'director@example.com',
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
        ]);

        app(\App\Services\WorkflowNotificationService::class)->notifyPOGenerated($mrf);

        Mail::assertSent(POGeneratedMail::class, function (POGeneratedMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });
    }
}
