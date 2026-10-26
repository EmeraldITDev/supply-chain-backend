<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PORegisterReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_register_api_filter_and_exports_use_vendor_business_identifier(): void
    {
        $viewer = User::factory()->create([
            'supply_chain_role' => 'procurement_manager',
        ]);
        Sanctum::actingAs($viewer);

        $vendor = Vendor::create([
            'vendor_id' => 'V-PO-023',
            'name' => 'Register Test Vendor',
            'category' => 'Materials',
            'email' => 'register-vendor@example.com',
            'status' => 'Active',
        ]);
        $requester = User::factory()->create();
        $mrf = MRF::create([
            'mrf_id' => 'MRF-REGISTER-001',
            'title' => 'Register test purchase',
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'date' => now()->toDateString(),
            'category' => 'Materials',
            'urgency' => 'Medium',
            'description' => 'PO Register reporting test',
            'quantity' => '1',
            'estimated_cost' => 100,
            'justification' => 'PO Register reporting test',
            'status' => 'approved',
            'workflow_state' => 'po_generated',
            'po_number' => 'PO-REGISTER-001',
            'po_generated_at' => now(),
            'selected_vendor_id' => $vendor->id,
            'po_value' => 100,
            'currency' => 'NGN',
        ]);
        MRFItem::create([
            'mrf_id' => $mrf->id,
            'item_name' => 'Test item',
            'quantity' => 1,
            'unit' => 'each',
            'unit_price' => 100,
            'total_price' => 100,
            'tax_rate' => 7.5,
        ]);

        $this->getJson('/api/reports/po-register?vendor=V-PO-023')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.id', $mrf->id)
            ->assertJsonPath('data.items.0.vendorName', 'Register Test Vendor')
            ->assertJsonPath('data.items.0.vendorCode', 'V-PO-023')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period' => ['from', 'to'],
                    'summary' => ['totalGeneratedPos', 'topVendorsByCurrency'],
                    'items',
                    'pagination' => ['page', 'perPage', 'total', 'totalPages'],
                ],
            ]);

        $this->get('/api/reports/po-register/export?format=xlsx&vendor=V-PO-023')
            ->assertOk();
        $this->get('/api/reports/po-register/export?format=pdf&vendor=V-PO-023')
            ->assertOk();
    }
}
