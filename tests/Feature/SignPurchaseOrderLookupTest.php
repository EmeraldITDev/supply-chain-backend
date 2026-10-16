<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\User;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SignPurchaseOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_resolves_the_formatted_id_the_dashboard_sends(): void
    {
        $director = User::factory()->create([
            'supply_chain_role' => 'supply_chain_director',
            'signature_image_path' => null,
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-INTERNAL-26003',
            'formatted_id' => 'MRF-EMERALD-PRC-EQP-2026-169',
            'po_number' => 'PO-2026-0609-171244-26003',
            'title' => 'Procurement of Business card with stone lamination',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'current_stage' => 'supply_chain',
            'unsigned_po_url' => 'https://s3.example/unsigned.pdf',
        ]);

        Sanctum::actingAs($director);

        $response = $this->postJson('/api/purchase-orders/'.rawurlencode($mrf->formatted_id).'/sign');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonMissing(['error' => 'PO not found']);
    }
}
