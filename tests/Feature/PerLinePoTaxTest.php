<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrderService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerLinePoTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_comparison_round_trips_per_line_tax_rate(): void
    {
        Sanctum::actingAs($this->procurementManager());
        $vendor = $this->vendor();
        $mrf = $this->mrf();

        $this->putJson('/api/mrfs/'.$mrf->mrf_id.'/price-comparisons', [
            'rows' => [
                ['vendor_id' => $vendor->vendor_id, 'item_description' => 'Pipe', 'unit_price' => 1000, 'quantity' => 2, 'is_selected' => true, 'tax_rate' => null],
                ['vendor_id' => $vendor->vendor_id, 'item_description' => 'Labour', 'unit_price' => 500, 'quantity' => 1, 'is_selected' => false, 'tax_rate' => 0],
                ['vendor_id' => $vendor->vendor_id, 'item_description' => 'Valve', 'unit_price' => 100, 'quantity' => 1, 'is_selected' => false, 'tax_rate' => 10],
            ],
        ])->assertOk();

        $rows = $this->getJson('/api/mrfs/'.$mrf->mrf_id.'/price-comparisons')
            ->assertOk()
            ->json('data');

        $byItem = collect($rows)->keyBy('item_description');
        $this->assertNull($byItem['Pipe']['tax_rate']);
        $this->assertSame(0, (int) $byItem['Labour']['tax_rate']);
        $this->assertEquals(10.0, $byItem['Valve']['tax_rate']);
        // Stored line totals stay pre-VAT (QuickBooks / GRN rely on this).
        $this->assertEquals(2000.0, $byItem['Pipe']['total_price']);
    }

    public function test_price_comparison_rejects_out_of_range_tax_rate(): void
    {
        Sanctum::actingAs($this->procurementManager());
        $vendor = $this->vendor();
        $mrf = $this->mrf();

        $this->putJson('/api/mrfs/'.$mrf->mrf_id.'/price-comparisons', [
            'rows' => [
                ['vendor_id' => $vendor->vendor_id, 'item_description' => 'Pipe', 'unit_price' => 1000, 'quantity' => 1, 'is_selected' => true, 'tax_rate' => 120],
            ],
        ])->assertStatus(422);
    }

    public function test_po_update_with_per_line_items_sets_vat_inclusive_totals(): void
    {
        $mrf = $this->mrf();

        app(PurchaseOrderService::class)->updateDraft($mrf, [
            'taxMode' => 'per_line',
            'items' => [
                ['itemName' => 'Pipe', 'quantity' => 2, 'unitPrice' => 1000],
                ['itemName' => 'Labour', 'quantity' => 1, 'unitPrice' => 500, 'taxRate' => 0],
            ],
        ]);

        $mrf->refresh();
        $this->assertSame('per_line', $mrf->tax_mode);
        $this->assertEquals(150.0, (float) $mrf->tax_amount);
        $this->assertEquals(2650.0, (float) $mrf->po_value);
        $this->assertEqualsCanonicalizing([null, 0.0], $mrf->items->map(
            fn ($i) => $i->tax_rate === null ? null : (float) $i->tax_rate
        )->all());
    }

    public function test_legacy_po_update_keeps_global_tax_behaviour(): void
    {
        $mrf = $this->mrf(['tax_rate' => 5, 'tax_amount' => 100]);

        app(PurchaseOrderService::class)->updateDraft($mrf, [
            'items' => [['itemName' => 'Pipe', 'quantity' => 2, 'unitPrice' => 1000]],
        ]);

        $mrf->refresh();
        $this->assertNull($mrf->tax_mode);
        $this->assertEquals(100.0, (float) $mrf->tax_amount);
        $this->assertEquals(2100.0, (float) $mrf->po_value);
    }

    public function test_po_update_applies_and_clears_discount_before_vat(): void
    {
        $mrf = $this->mrf();
        $items = [
            ['itemName' => 'Pipe', 'quantity' => 2, 'unitPrice' => 1000],
            ['itemName' => 'Labour', 'quantity' => 1, 'unitPrice' => 500, 'taxRate' => 0],
        ];

        app(PurchaseOrderService::class)->updateDraft($mrf, [
            'taxMode' => 'per_line',
            'discountType' => 'amount',
            'discountValue' => 250,
            'items' => $items,
        ]);
        $mrf->refresh();
        $this->assertSame('amount', $mrf->discount_type);
        $this->assertEquals(250.0, (float) $mrf->discount_amount);
        $this->assertEquals(135.0, (float) $mrf->tax_amount);
        $this->assertEquals(2385.0, (float) $mrf->po_value);

        app(PurchaseOrderService::class)->updateDraft($mrf, [
            'taxMode' => 'per_line',
            'discountType' => null,
            'items' => $items,
        ]);
        $mrf->refresh();
        $this->assertNull($mrf->discount_type);
        $this->assertNull($mrf->discount_amount);
        $this->assertEquals(2650.0, (float) $mrf->po_value);
    }

    private function procurementManager(): User
    {
        return User::factory()->create([
            'name' => 'Procurement Manager',
            'supply_chain_role' => 'procurement_manager',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create([
            'vendor_id' => 'V-TAX-001',
            'name' => 'Acme Supplies',
            'email' => 'acme@example.com',
            'status' => 'Active',
            'category' => 'General',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mrf(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => 'MRF-TAX-'.uniqid(),
            'title' => 'Pipes',
            'category' => 'goods',
            'urgency' => 'Medium',
            'description' => 'Pipes',
            'quantity' => '1',
            'justification' => 'Test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'status' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'current_stage' => 'procurement',
            'estimated_cost' => 1000,
        ], $overrides));
    }
}
