<?php

namespace Tests\Feature;

use App\Mail\PORevisedForResignMail;
use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrderRevisionService;
use App\Services\RefreshUnsignedPurchaseOrderPdfService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SignedPoRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlock_for_edit_is_gated_to_procurement_manager_and_admin(): void
    {
        $mrf = $this->makeSignedPo();
        $employee = User::factory()->create(['supply_chain_role' => 'employee']);

        Sanctum::actingAs($employee);

        $this->postJson('/api/pos/'.$mrf->mrf_id.'/unlock-for-edit', [
            'reason' => 'Need to correct quantity',
        ])->assertForbidden();
    }

    public function test_unlock_for_edit_requires_signed_status_and_reason(): void
    {
        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);

        $draft = $this->makeSignedPo([
            'status' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'signed_po_url' => null,
        ]);

        $this->postJson('/api/pos/'.$draft->mrf_id.'/unlock-for-edit', [
            'reason' => 'Trying to unlock a draft',
        ])->assertStatus(422);

        $signed = $this->makeSignedPo();
        $this->postJson('/api/pos/'.$signed->mrf_id.'/unlock-for-edit', [])
            ->assertStatus(422);
    }

    public function test_unlock_clears_signed_url_preserves_unsigned_and_records_audit_fields(): void
    {
        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);
        $mrf = $this->makeSignedPo();
        $unsigned = $mrf->unsigned_po_url;

        $response = $this->postJson('/api/pos/'.$mrf->mrf_id.'/unlock-for-edit', [
            'reason' => 'Supplier quoted a new unit price',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_revision')
            ->assertJsonPath('data.workflowState', 'pending_revision');

        $mrf->refresh();
        $this->assertNull($mrf->signed_po_url);
        $this->assertSame($unsigned, $mrf->unsigned_po_url);
        $this->assertSame($pm->id, $mrf->unlocked_by);
        $this->assertSame('Supplier quoted a new unit price', $mrf->unlock_reason);
        $this->assertNotNull($mrf->unlocked_at);
        $this->assertNotEmpty($mrf->revision_snapshot);
    }

    public function test_update_is_blocked_while_signed_and_allowed_in_pending_revision(): void
    {
        $pm = $this->procurementManager();
        Sanctum::actingAs($pm);
        $mrf = $this->makeSignedPo();

        $this->putJson('/api/pos/'.$mrf->mrf_id, [
            'paymentTerms' => 'Net 45',
            'items' => [[
                'itemName' => 'Pipe',
                'quantity' => 4,
                'unitPrice' => 250,
            ]],
        ])->assertStatus(422);

        $this->postJson('/api/pos/'.$mrf->mrf_id.'/unlock-for-edit', [
            'reason' => 'Correct line quantities',
        ])->assertOk();

        $this->putJson('/api/pos/'.$mrf->mrf_id, [
            'paymentTerms' => 'Net 45',
            'expectedDeliveryDate' => '2026-10-01',
            'remarks' => 'Updated after supplier confirmation',
            'items' => [[
                'itemName' => 'Pipe',
                'quantity' => 4,
                'unitPrice' => 250,
            ]],
        ])->assertOk()
            ->assertJsonPath('success', true);

        $mrf->refresh();
        $this->assertSame('Net 45', $mrf->po_payment_terms);
        $this->assertSame('2026-10-01', $mrf->expected_delivery_date?->format('Y-m-d'));
        $this->assertSame(1, $mrf->items()->count());
        $this->assertEquals(4, $mrf->items()->first()?->quantity);
        $this->assertEquals(250, (float) $mrf->items()->first()?->unit_price);
    }

    public function test_submit_for_resign_diffs_changes_notifies_scd_and_sets_pending_signature(): void
    {
        Mail::fake();

        $this->mock(RefreshUnsignedPurchaseOrderPdfService::class, function ($mock) {
            $mock->shouldReceive('refresh')->andReturnUsing(function (MRF $mrf) {
                $mrf->update(['unsigned_po_url' => 'https://s3.example/revised-po.pdf']);

                return ['success' => true, 'mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number];
            });
        });

        $pm = $this->procurementManager();
        $director = User::factory()->create([
            'name' => 'Supply Chain Director',
            'email' => 'scd@example.com',
            'supply_chain_role' => 'supply_chain_director',
        ]);
        Sanctum::actingAs($pm);

        $mrf = $this->makeSignedPo();
        $this->postJson('/api/pos/'.$mrf->mrf_id.'/unlock-for-edit', [
            'reason' => 'Price correction',
        ])->assertOk();

        $this->putJson('/api/pos/'.$mrf->mrf_id, [
            'items' => [[
                'itemName' => 'Pipe',
                'quantity' => 8,
                'unitPrice' => 175,
            ]],
        ])->assertOk();

        $response = $this->postJson('/api/pos/'.$mrf->mrf_id.'/submit-for-resign');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_scd_signature')
            ->assertJsonPath('revision_number', 1);

        $mrf->refresh();
        $this->assertSame('pending_scd_signature', $mrf->status);
        $this->assertSame(1, (int) $mrf->revision_number);
        $this->assertSame('https://s3.example/revised-po.pdf', $mrf->unsigned_po_url);
        $this->assertNotEmpty($mrf->revision_history);
        $fields = collect($mrf->revision_history[0]['changed_fields'] ?? [])->pluck('field');
        $this->assertTrue($fields->contains(fn ($field) => str_contains((string) $field, 'quantity')));
        $this->assertTrue($fields->contains(fn ($field) => str_contains((string) $field, 'unit_price')));

        Mail::assertSent(PORevisedForResignMail::class, function (PORevisedForResignMail $mail) use ($director) {
            return $mail->hasTo($director->email);
        });

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $director->id,
        ]);
    }

    public function test_revision_diff_calls_out_total_quantity_price_and_supplier(): void
    {
        $service = app(PurchaseOrderRevisionService::class);
        $before = [
            'total_value' => 1000,
            'supplier' => 'Acme',
            'expected_delivery_date' => '2026-09-01',
            'payment_terms' => 'Net 30',
            'notes' => 'Original',
            'items' => [[
                'item_name' => 'Pipe',
                'quantity' => 2,
                'unit_price' => 500,
                'total_price' => 1000,
            ]],
        ];
        $after = [
            'total_value' => 1600,
            'supplier' => 'Globex',
            'expected_delivery_date' => '2026-09-01',
            'payment_terms' => 'Net 30',
            'notes' => 'Original',
            'items' => [[
                'item_name' => 'Pipe',
                'quantity' => 4,
                'unit_price' => 400,
                'total_price' => 1600,
            ]],
        ];

        $changes = collect($service->diffSnapshots($before, $after))->pluck('field');
        $this->assertTrue($changes->contains('total_value'));
        $this->assertTrue($changes->contains('supplier'));
        $this->assertTrue($changes->contains('line_item_quantity_0'));
        $this->assertTrue($changes->contains('line_item_unit_price_0'));
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
    private function makeSignedPo(array $overrides = []): MRF
    {
        $vendor = Vendor::create([
            'name' => 'Acme Supplies',
            'email' => 'acme@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create(array_merge([
            'mrf_id' => 'MRF-REV-'.uniqid(),
            'title' => 'Pipes',
            'status' => 'signed',
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
            'current_stage' => 'finance',
            'po_number' => 'PO-REV-001',
            'unsigned_po_url' => 'https://s3.example/unsigned.pdf',
            'signed_po_url' => 'https://s3.example/signed.pdf',
            'po_signed_at' => now(),
            'po_value' => 1000,
            'estimated_cost' => 1000,
            'selected_vendor_id' => $vendor->id,
            'po_payment_terms' => 'Net 30',
            'expected_delivery_date' => '2026-09-01',
            'revision_number' => 0,
        ], $overrides));

        MRFItem::create([
            'mrf_id' => $mrf->id,
            'item_name' => 'Pipe',
            'quantity' => 2,
            'unit' => 'unit',
            'unit_price' => 500,
            'total_price' => 1000,
        ]);

        return $mrf->fresh(['items', 'selectedVendor']);
    }
}
