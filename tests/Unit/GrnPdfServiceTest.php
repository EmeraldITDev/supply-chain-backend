<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\User;
use App\Services\GrnPdfService;
use Tests\TestCase;

class GrnPdfServiceTest extends TestCase
{
    public function test_html_matches_branded_grn_layout(): void
    {
        $service = app(GrnPdfService::class);
        $mrf = new MRF([
            'mrf_id' => 'MRF-OANDO-PRC-MAIN-2026-029',
            'formatted_id' => 'MRF-OANDO-PRC-MAIN-2026-029',
            'requester_name' => 'Asuku Onukaba',
        ]);
        $mrf->setRelation('items', collect([
            new MRFItem([
                'item_name' => 'HP Elitebook 840 G5',
                'description' => 'Laptop',
                'quantity' => 1,
                'unit' => 'Pack',
                'unit_price' => 470000,
            ]),
        ]));

        $user = new User([
            'name' => 'Asuku Onukaba',
            'email' => 'asuku.onukaba@emeraldcfze.com',
            'phone' => '08121573451',
            'role' => 'procurement_manager',
            'department' => 'Technical Specialist',
        ]);

        $html = $service->html($mrf, $user, [
            'grn_number' => 'GRN-MRF-OANDO-PRC-MAIN-2026-029-2026-001',
            'date_of_receipt' => '2026-02-02',
            'delivery_note_number' => 'DN-001',
            'delivery_date' => '2026-04-10',
            'supplier_name' => 'Enirol Technology',
            'supplier_address' => "Aa plaza,suite 25,\n18, simbiat abiola way",
        ]);

        $this->assertStringContainsString('GOODS RECEIVED NOTE', $html);
        $this->assertStringContainsString('GRN Number:', $html);
        $this->assertStringContainsString('Date of Receipt:', $html);
        $this->assertStringContainsString('Delivery Information', $html);
        $this->assertStringContainsString('Supplier Information', $html);
        $this->assertStringContainsString('Material Received Note', $html);
        $this->assertStringContainsString('Quantity Ordered', $html);
        $this->assertStringContainsString('Quantity Received', $html);
        $this->assertStringContainsString('Unit Price', $html);
        $this->assertStringContainsString('470,000', $html);
        $this->assertStringContainsString('Authorized signatories', $html);
        $this->assertStringContainsString('Vendor (delivered by)', $html);
        $this->assertStringContainsString('Emerald (Received by)', $html);
        $this->assertStringContainsString('Emerald (supervised by)', $html);
        $this->assertStringContainsString('Site Manager', $html);
        $this->assertStringContainsString('COMMENTS:', $html);
    }

    public function test_default_grn_number_uses_mrf_id_year_sequence_format(): void
    {
        $service = app(GrnPdfService::class);

        $mrf = new MRF([
            'id' => 99,
            'mrf_id' => 'MRF-OANDO-PRC-MAIN-2026-029',
        ]);

        $this->assertMatchesRegularExpression(
            '/^GRN-MRF-OANDO-PRC-MAIN-2026-029-' . now()->format('Y') . '-\d{3}$/',
            $service->defaultGrnNumber($mrf)
        );
    }

    public function test_quantity_received_override_recalculates_total(): void
    {
        $service = app(GrnPdfService::class);
        $mrf = new MRF(['mrf_id' => 'MRF-TEST-003']);
        $mrf->setRelation('items', collect([
            new MRFItem([
                'item_name' => 'HP Elitebook 840 G5',
                'description' => 'Laptop',
                'quantity' => 2,
                'unit' => 'Pack',
                'unit_price' => 470000,
            ]),
        ]));

        $resolved = $service->resolveLineItems($mrf, [
            ['index' => 0, 'quantity_received' => 1, 'unit_price' => 470000],
        ]);

        $this->assertTrue($resolved['success']);
        $this->assertSame('1', $resolved['line_items'][0]['quantity_received']);
        $this->assertSame('2', $resolved['line_items'][0]['quantity_ordered']);
        $this->assertSame('470,000', $resolved['line_items'][0]['unit_price']);
        $this->assertSame('470,000', $resolved['line_items'][0]['total']);
    }
}
