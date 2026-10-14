<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Services\QuickBooksPayloadValidator;
use App\Services\QuickBooksPOSyncService;
use App\Services\QuickBooksService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class QuickBooksPayloadValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.default_item_id' => '88',
            'services.quickbooks.default_expense_account_id' => null,
            'services.quickbooks.ap_account_id' => null,
            'services.quickbooks.doc_number_max_length' => 21,
        ]);
    }

    public function test_long_doc_number_is_omitted_and_item_ref_is_not_hardcoded(): void
    {
        $mrf = new MRF([
            'formatted_id' => 'MRF-EMPLOYEE-BD-CON-2026-083',
            'po_number' => 'PO-180926-BLUEGATE-0002',
            'po_value' => 1000,
            'currency' => 'NGN',
            'signed_po_url' => 'https://example.test/signed.pdf',
        ]);
        $mrf->setRelation('items', new Collection([
            new MRFItem([
                'item_name' => 'Widget',
                'quantity' => 2,
                'unit_price' => 500,
                'total_price' => 1000,
            ]),
        ]));

        $built = app(QuickBooksPOSyncService::class)->buildPayload($mrf, [
            'vendor_id' => '55',
            'vendor_action' => 'matched',
            'home_currency' => 'NGN',
            'multi_currency' => false,
            'ap_accounts' => [['Id' => '33', 'Name' => 'Accounts Payable']],
        ]);

        $this->assertArrayNotHasKey('DocNumber', $built['payload']);
        $this->assertArrayNotHasKey('CustomField', $built['payload']);
        $this->assertStringContainsString('PO-180926-BLUEGATE-0002', $built['payload']['PrivateNote']);
        $this->assertStringContainsString('PO-180926-BLUEGATE-0002', $built['payload']['VendorMemo']);
        $this->assertSame('88', $built['payload']['Line'][0]['ItemBasedExpenseLineDetail']['ItemRef']['value']);
        $this->assertNotSame('1', $built['payload']['Line'][0]['ItemBasedExpenseLineDetail']['ItemRef']['value']);

        $validation = app(QuickBooksPayloadValidator::class)->validate($built['payload'], $built['meta'], false);
        $docNumber = collect($validation['results'])->firstWhere('check', 'doc_number');

        $this->assertNotNull($docNumber);
        $this->assertTrue($docNumber['passed']);
    }

    public function test_validator_rejects_a_total_mismatch(): void
    {
        $validation = app(QuickBooksPayloadValidator::class)->validate($this->payload('88', 10), [
            'po_number' => 'PO-1',
            'currency' => 'NGN',
            'home_currency' => 'NGN',
            'multi_currency' => false,
            'vendor_action' => 'matched',
            'ap_accounts' => [['Id' => '33', 'Name' => 'Accounts Payable']],
            'line_mode' => 'item',
            'expected_total' => 99,
            'assumptions' => [],
        ], false);

        $total = collect($validation['results'])->firstWhere('check', 'total');

        $this->assertFalse($validation['passed']);
        $this->assertFalse($total['passed']);
    }

    public function test_validator_rejects_an_item_ref_that_does_not_exist(): void
    {
        $this->mock(QuickBooksService::class, function ($mock) {
            $mock->shouldReceive('entityExists')->andReturnUsing(function (string $entity, string $id) {
                return ! ($entity === 'item' && $id === 'MISSING');
            });
        });

        $validation = app(QuickBooksPayloadValidator::class)->validate($this->payload('MISSING', 10), [
            'po_number' => 'PO-1',
            'currency' => 'NGN',
            'home_currency' => 'NGN',
            'multi_currency' => false,
            'vendor_action' => 'matched',
            'ap_accounts' => [['Id' => '33', 'Name' => 'Accounts Payable']],
            'line_mode' => 'item',
            'expected_total' => 10,
            'assumptions' => [],
        ], true, 1);

        $item = collect($validation['results'])->firstWhere('check', 'item_ref_MISSING');

        $this->assertFalse($validation['passed']);
        $this->assertNotNull($item);
        $this->assertFalse($item['passed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $itemId, float $amount): array
    {
        return [
            'POStatus' => 'Open',
            'TxnDate' => '2026-10-13',
            'DocNumber' => 'PO-1',
            'VendorRef' => ['value' => '55'],
            'PrivateNote' => 'SCM reference',
            'VendorMemo' => 'SCM reference',
            'Line' => [[
                'Amount' => $amount,
                'Description' => 'Widget',
                'LineNum' => 1,
                'DetailType' => 'ItemBasedExpenseLineDetail',
                'ItemBasedExpenseLineDetail' => [
                    'ItemRef' => ['value' => $itemId],
                    'Qty' => 2,
                    'UnitPrice' => 5,
                ],
            ]],
        ];
    }
}
