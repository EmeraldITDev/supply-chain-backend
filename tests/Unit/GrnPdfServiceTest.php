<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Services\GrnPdfService;
use Tests\TestCase;

class GrnPdfServiceTest extends TestCase
{
    public function test_html_includes_line_items(): void
    {
        $service = app(GrnPdfService::class);
        $mrf = new MRF([
            'mrf_id' => 'MRF-TEST-001',
            'formatted_id' => 'MRF-EMERALD-2026-TEST',
            'po_number' => 'PO-123',
            'department' => 'Operations',
        ]);

        $html = $service->html($mrf, [
            [
                'name' => 'Industrial Pump',
                'description' => 'Stainless steel body',
                'quantity' => '2',
                'unit' => 'pcs',
            ],
        ], [
            'grn_number' => 'GRN-TEST-001',
            'remarks' => 'Received in good condition',
        ]);

        $this->assertStringContainsString('Goods Received Note', $html);
        $this->assertStringContainsString('Industrial Pump', $html);
        $this->assertStringContainsString('GRN-TEST-001', $html);
        $this->assertStringContainsString('PO-123', $html);
    }

    public function test_default_grn_number_uses_po_or_mrf_reference(): void
    {
        $service = app(GrnPdfService::class);

        $mrf = new MRF([
            'mrf_id' => 'MRF-TEST-002',
            'po_number' => 'PO-999',
        ]);

        $this->assertStringStartsWith('GRN-PO-999-', $service->defaultGrnNumber($mrf));
    }
}
