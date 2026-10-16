<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Services\SignedPurchaseOrderRepairService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedPurchaseOrderRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_po_stuck_as_signature_pending_is_restored(): void
    {
        $mrf = MRF::create([
            'mrf_id' => 'MRF-SIGNED-STUCK-001',
            'requester_name' => 'Requester',
            'title' => 'Already signed',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => 'po_generated',
            'current_stage' => 'supply_chain',
            'po_number' => 'PO-SIGNED-STUCK-001',
            'signed_po_url' => 'https://files.example/signed/po.pdf',
            'po_signed_at' => now(),
        ]);

        $restored = app(SignedPurchaseOrderRepairService::class)->restore($mrf->fresh());

        $mrf->refresh();

        $this->assertTrue($restored);
        $this->assertSame('signed', $mrf->status);
        $this->assertSame(WorkflowStateService::STATE_PO_SIGNED, $mrf->workflow_state);
        $this->assertSame('finance', $mrf->current_stage);
    }

    public function test_unsigned_po_stays_in_the_signature_queue(): void
    {
        $mrf = MRF::create([
            'mrf_id' => 'MRF-UNSIGNED-001',
            'requester_name' => 'Requester',
            'title' => 'Still unsigned',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => 'po_generated',
            'po_number' => 'PO-UNSIGNED-001',
            'unsigned_po_url' => 'https://files.example/unsigned/po.pdf',
        ]);

        $restored = app(SignedPurchaseOrderRepairService::class)->restore($mrf->fresh());

        $mrf->refresh();

        $this->assertFalse($restored);
        $this->assertSame('awaiting_scd_signature', $mrf->status);
        $this->assertSame('po_generated', $mrf->workflow_state);
    }
}
