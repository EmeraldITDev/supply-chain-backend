<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Services\Finance\FinanceApOpenPurchaseOrderService;
use App\Services\Finance\FinancePackageBuilder;
use App\Services\PurchaseOrderPdfService;
use App\Services\QuickBooksPOSyncService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Downstream readers of PO totals must stay correct for per-line VAT POs
 * (tax_mode = per_line) without changing legacy POs.
 */
class PerLinePoTaxConsumersTest extends TestCase
{
    private function perLineMrf(): MRF
    {
        // 2 × 1000 @ default 7.5% (150) + 1 × 500 @ 0% → subtotal 2500, VAT 150, total 2650.
        $mrf = new MRF([
            'formatted_id' => 'MRF-TEST-001',
            'po_number' => 'PO-TEST-0001',
            'po_value' => 2650,
            'tax_amount' => 150,
            'tax_rate' => 0,
            'tax_mode' => 'per_line',
            'estimated_cost' => 2500,
            'currency' => 'NGN',
            'signed_po_url' => 'https://example.test/signed.pdf',
        ]);
        $mrf->setRelation('items', new Collection([
            new MRFItem(['item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 1000, 'total_price' => 2000, 'tax_rate' => null]),
            new MRFItem(['item_name' => 'Exempt', 'quantity' => 1, 'unit_price' => 500, 'total_price' => 500, 'tax_rate' => 0]),
        ]));

        return $mrf;
    }

    public function test_quickbooks_expected_total_stays_pre_vat_for_per_line_po(): void
    {
        config(['services.quickbooks.default_item_id' => '88']);

        $built = app(QuickBooksPOSyncService::class)->buildPayload($this->perLineMrf(), [
            'vendor_id' => '55',
            'vendor_action' => 'matched',
            'home_currency' => 'NGN',
            'multi_currency' => false,
            'ap_accounts' => [['Id' => '33', 'Name' => 'Accounts Payable']],
        ]);

        $lineSum = array_sum(array_column($built['payload']['Line'], 'Amount'));
        $this->assertSame(2500.0, (float) $lineSum);
        $this->assertSame(2500.0, (float) $built['meta']['expected_total']);
    }

    public function test_finance_ap_total_uses_vat_inclusive_po_value_for_per_line_po(): void
    {
        $service = app(FinanceApOpenPurchaseOrderService::class);
        $method = new \ReflectionMethod($service, 'resolvePoTotal');

        $this->assertSame(2650.0, $method->invoke($service, $this->perLineMrf(), null));

        $builder = app(FinancePackageBuilder::class);
        $method = new \ReflectionMethod($builder, 'resolvePoTotal');
        $this->assertSame(2650.0, $method->invoke($builder, $this->perLineMrf(), null));
    }

    public function test_finance_ap_total_unchanged_for_legacy_po(): void
    {
        $mrf = new MRF(['po_value' => 2687.5, 'tax_amount' => 187.5, 'estimated_cost' => 2500]);
        $mrf->setRelation('rfqs', new Collection([
            tap(new \App\Models\RFQ(), fn ($rfq) => $rfq->setRelation('selectedQuotation', null)),
        ]));

        $service = app(FinanceApOpenPurchaseOrderService::class);
        $method = new \ReflectionMethod($service, 'resolvePoTotal');

        // Legacy keeps the pre-existing estimated_cost fallback (not po_value).
        $this->assertSame(2500.0, $method->invoke($service, $mrf, null));
    }

    public function test_workflow_pdf_shows_per_line_rates_and_vat_inclusive_amounts(): void
    {
        $html = app(PurchaseOrderPdfService::class)->htmlFromWorkflow([
            'mrf' => ['po_type' => 'goods', 'currency' => 'NGN', 'tax_rate' => 0, 'tax_mode' => 'per_line'],
            'quotation' => ['currency' => 'NGN'],
            'vendor' => ['name' => 'Acme Supplies'],
            'company' => ['name' => 'Emerald Industrial Co. FZE'],
            'items' => [
                ['item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 1000, 'total_price' => 2000, 'tax_rate' => null],
                ['item_name' => 'Exempt', 'quantity' => 1, 'unit_price' => 500, 'total_price' => 500, 'tax_rate' => 0],
            ],
            // Stale global figures must not leak into a per-line PO.
            'tax_rate' => 5,
            'tax_amount' => 999,
            'tax_mode' => 'per_line',
        ], 'PO-TEST-0001', (object) ['name' => 'Tester']);

        $this->assertStringContainsString('7.5%', $html);
        $this->assertStringContainsString('2,150.00', $html); // Widget, VAT-inclusive
        $this->assertStringContainsString('2,650.00', $html); // PO total
        $this->assertStringNotContainsString('999.00', $html);
        $this->assertStringNotContainsString('>5%<', $html);
    }

    public function test_workflow_pdf_legacy_po_is_unchanged(): void
    {
        $html = app(PurchaseOrderPdfService::class)->htmlFromWorkflow([
            'mrf' => ['po_type' => 'goods', 'currency' => 'NGN', 'tax_rate' => 5],
            'quotation' => ['currency' => 'NGN'],
            'vendor' => ['name' => 'Acme Supplies'],
            'company' => ['name' => 'Emerald Industrial Co. FZE'],
            'items' => [
                ['item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 1000, 'total_price' => 2000],
            ],
            'tax_rate' => 5,
        ], 'PO-TEST-0002', (object) ['name' => 'Tester']);

        $this->assertStringContainsString('5%', $html);
        $this->assertStringContainsString('2,000.00', $html); // pre-VAT line amount
        $this->assertStringContainsString('2,100.00', $html); // total
    }

    public function test_quickbooks_sends_negative_discount_line_that_validates(): void
    {
        config(['services.quickbooks.default_item_id' => '88']);
        // Subtotal 2500, 10% discount 250, VAT 135, total 2385.
        $mrf = $this->perLineMrf();
        $mrf->fill(['discount_type' => 'percentage', 'discount_value' => 10, 'discount_amount' => 250, 'tax_amount' => 135, 'po_value' => 2385]);

        $built = app(QuickBooksPOSyncService::class)->buildPayload($mrf, [
            'vendor_id' => '55',
            'vendor_action' => 'matched',
            'home_currency' => 'NGN',
            'multi_currency' => false,
            'ap_accounts' => [['Id' => '33', 'Name' => 'Accounts Payable']],
        ]);

        $last = end($built['payload']['Line']);
        $this->assertSame('Discount', $last['Description']);
        $this->assertSame(-250.0, (float) $last['Amount']);
        $this->assertSame(2250.0, (float) $built['meta']['expected_total']);

        $validation = app(\App\Services\QuickBooksPayloadValidator::class)->validate($built['payload'], $built['meta'], false);
        $failed = collect($validation['results'])->where('passed', false)->pluck('check')->all();
        $this->assertNotContains('total', $failed);
        $this->assertEmpty(array_filter($failed, fn ($c) => str_starts_with($c, 'line_')));
    }

    public function test_workflow_pdf_shows_discount_row_and_note(): void
    {
        $html = app(PurchaseOrderPdfService::class)->htmlFromWorkflow([
            'mrf' => ['po_type' => 'goods', 'currency' => 'NGN', 'tax_mode' => 'per_line'],
            'quotation' => ['currency' => 'NGN'],
            'vendor' => ['name' => 'Acme Supplies'],
            'company' => ['name' => 'Emerald Industrial Co. FZE'],
            'items' => [
                ['item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 1000, 'tax_rate' => null],
                ['item_name' => 'Exempt', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
            ],
            'tax_mode' => 'per_line',
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ], 'PO-TEST-0003', (object) ['name' => 'Tester']);

        $this->assertStringContainsString('DISCOUNT', $html);
        $this->assertStringContainsString('-250.00', $html);
        $this->assertStringContainsString('Discount: 10% of subtotal (before VAT)', $html);
        $this->assertStringContainsString('135.00', $html);
        $this->assertStringContainsString('2,385.00', $html);
    }
}
