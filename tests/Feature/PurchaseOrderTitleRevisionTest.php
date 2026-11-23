<?php

namespace Tests\Feature;

use App\Mail\PORevisedForResignMail;
use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\RefreshUnsignedPurchaseOrderPdfService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderTitleRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_title_can_be_edited_directly_by_procurement_manager(): void
    {
        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);

        $mrf = $this->makeDraftPo([
            'title' => 'MUS-295KM FORD TRANSIT SERVICING',
        ]);

        $response = $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'MUS-275KM FORD TRANSIT SERVICING',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('revision_required', false)
            ->assertJsonPath('previous_title', 'MUS-295KM FORD TRANSIT SERVICING')
            ->assertJsonPath('new_title', 'MUS-275KM FORD TRANSIT SERVICING')
            ->assertJsonPath('data.title', 'MUS-275KM FORD TRANSIT SERVICING');

        $mrf->refresh();
        $this->assertSame('MUS-275KM FORD TRANSIT SERVICING', $mrf->title);
        $this->assertSame(0, (int) $mrf->revision_number);
        $this->assertEmpty($mrf->revision_history ?? []);
    }

    public function test_submitted_po_title_change_creates_controlled_revision(): void
    {
        Mail::fake();

        $this->mock(RefreshUnsignedPurchaseOrderPdfService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andReturnUsing(function (MRF $mrf) {
                $mrf->update(['unsigned_po_url' => 'https://s3.example/title-revised.pdf']);

                return ['success' => true, 'mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number];
            });
        });

        $pm = $this->procurementManager();
        $director = User::factory()->create([
            'name' => 'Supply Chain Director',
            'email' => 'scd-title@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        Sanctum::actingAs($pm);

        $mrf = $this->makeSubmittedPo([
            'title' => 'MUS-295KM FORD TRANSIT SERVICING',
            'po_number' => 'PO-231126-PRECISIONGARAGE-0004',
            'unsigned_po_url' => 'https://s3.example/original.pdf',
        ]);

        $response = $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'MUS-275KM FORD TRANSIT SERVICING',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('revision_required', true)
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('data.status', 'pending_scd_signature')
            ->assertJsonPath('data.awaitingRenewedApproval', true);

        $mrf->refresh();
        $this->assertSame('MUS-275KM FORD TRANSIT SERVICING', $mrf->title);
        $this->assertSame(1, (int) $mrf->revision_number);
        $this->assertSame('https://s3.example/original.pdf', $mrf->revision_history[0]['previous_unsigned_po_url'] ?? null);
        $this->assertSame('MUS-295KM FORD TRANSIT SERVICING', $mrf->revision_history[0]['previous_title'] ?? null);
        $this->assertSame('MUS-275KM FORD TRANSIT SERVICING', $mrf->revision_history[0]['new_title'] ?? null);
        $this->assertSame($pm->id, $mrf->revision_history[0]['editor_user_id'] ?? null);
        $this->assertNotEmpty($mrf->revision_history[0]['timestamp'] ?? null);

        $fields = collect($mrf->revision_history[0]['changed_fields'] ?? [])->pluck('field');
        $this->assertTrue($fields->contains('title'));

        Mail::assertSent(PORevisedForResignMail::class, function (PORevisedForResignMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });
    }

    public function test_signed_po_title_change_requires_unlock_then_appears_in_resign_diff(): void
    {
        Mail::fake();

        $this->mock(RefreshUnsignedPurchaseOrderPdfService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andReturnUsing(function (MRF $mrf) {
                $mrf->update(['unsigned_po_url' => 'https://s3.example/signed-title-revised.pdf']);

                return ['success' => true, 'mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number];
            });
        });

        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);
        $mrf = $this->makeSignedPo([
            'title' => 'MUS-295KM FORD TRANSIT SERVICING',
        ]);

        $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'MUS-275KM FORD TRANSIT SERVICING',
        ])->assertStatus(422);

        $this->postJson('/api/pos/'.$mrf->mrf_id.'/unlock-for-edit', [
            'reason' => 'Correct vehicle registration in title',
        ])->assertOk();

        $this->putJson('/api/pos/'.$mrf->mrf_id, [
            'title' => 'MUS-275KM FORD TRANSIT SERVICING',
            'paymentTerms' => 'Net 30',
            'items' => [[
                'itemName' => 'Service',
                'quantity' => 1,
                'unitPrice' => 1000,
            ]],
        ])->assertOk();

        $response = $this->postJson('/api/pos/'.$mrf->mrf_id.'/submit-for-resign');
        $response->assertOk()
            ->assertJsonPath('revision_number', 1);

        $mrf->refresh();
        $this->assertSame('MUS-275KM FORD TRANSIT SERVICING', $mrf->title);
        $fields = collect($mrf->revision_history[0]['changed_fields'] ?? [])->pluck('field');
        $this->assertTrue($fields->contains('title'));
        $titleChange = collect($mrf->revision_history[0]['changed_fields'] ?? [])
            ->firstWhere('field', 'title');
        $this->assertSame('MUS-295KM FORD TRANSIT SERVICING', $titleChange['before'] ?? null);
        $this->assertSame('MUS-275KM FORD TRANSIT SERVICING', $titleChange['after'] ?? null);
    }

    public function test_unauthorised_and_invalid_title_changes_are_rejected(): void
    {
        $employee = User::factory()->create(['supply_chain_role' => 'employee']);
        Sanctum::actingAs($employee);

        $mrf = $this->makeDraftPo(['title' => 'Original Title']);

        $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'Hacked Title',
        ])->assertForbidden();

        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);

        $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => '',
        ])->assertStatus(422);

        $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'ab',
        ])->assertStatus(422);

        $mrf->refresh();
        $this->assertSame('Original Title', $mrf->title);
    }

    public function test_supply_chain_director_can_edit_draft_title(): void
    {
        $scd = User::factory()->create([
            'supply_chain_role' => 'supply_chain_director',
        ]);
        Sanctum::actingAs($scd);

        $mrf = $this->makeDraftPo(['title' => 'Old SCD Title']);

        $this->patchJson('/api/pos/'.$mrf->mrf_id.'/title', [
            'title' => 'New SCD Title',
        ])->assertOk()
            ->assertJsonPath('revision_required', false)
            ->assertJsonPath('data.title', 'New SCD Title');
    }

    private function procurementManager(): User
    {
        return User::factory()->create([
            'name' => 'Procurement Manager',
            'supply_chain_role' => 'procurement_manager',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftPo(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => 'MRF-TITLE-DRAFT-'.uniqid(),
            'title' => 'Draft Title',
            'status' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'current_stage' => 'procurement',
            'po_number' => 'PO-DRAFT-'.uniqid(),
            'po_draft_saved_at' => now(),
            'unsigned_po_url' => null,
            'signed_po_url' => null,
            'revision_number' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSubmittedPo(array $overrides = []): MRF
    {
        $vendor = Vendor::create([
            'name' => 'Precision Garage',
            'email' => 'precision@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create(array_merge([
            'mrf_id' => 'MRF-EMERALD-PRC-EQP-2026-264',
            'title' => 'Submitted Title',
            'status' => 'awaiting_scd_signature',
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'current_stage' => 'supply_chain',
            'po_number' => 'PO-SUBMITTED-001',
            'unsigned_po_url' => 'https://s3.example/submitted.pdf',
            'signed_po_url' => null,
            'po_value' => 1000,
            'estimated_cost' => 1000,
            'selected_vendor_id' => $vendor->id,
            'revision_number' => 0,
        ], $overrides));

        MRFItem::create([
            'mrf_id' => $mrf->id,
            'item_name' => 'Service',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);

        return $mrf->fresh(['items', 'selectedVendor']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSignedPo(array $overrides = []): MRF
    {
        $vendor = Vendor::create([
            'name' => 'Acme Supplies',
            'email' => 'acme-title@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create(array_merge([
            'mrf_id' => 'MRF-TITLE-SIGNED-'.uniqid(),
            'title' => 'Signed Title',
            'status' => 'signed',
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
            'current_stage' => 'finance',
            'po_number' => 'PO-TITLE-SIGNED-001',
            'unsigned_po_url' => 'https://s3.example/unsigned.pdf',
            'signed_po_url' => 'https://s3.example/signed.pdf',
            'po_signed_at' => now(),
            'po_value' => 1000,
            'estimated_cost' => 1000,
            'selected_vendor_id' => $vendor->id,
            'po_payment_terms' => 'Net 30',
            'revision_number' => 0,
        ], $overrides));

        MRFItem::create([
            'mrf_id' => $mrf->id,
            'item_name' => 'Service',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);

        return $mrf->fresh(['items', 'selectedVendor']);
    }
}
