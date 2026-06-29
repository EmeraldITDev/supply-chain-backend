<?php

namespace Tests\Unit;

use App\Models\PaymentSchedule;
use App\Models\ProcurementDocument;
use App\Services\Finance\FinanceApPaymentTypeMapper;
use App\Services\Finance\FinancePackageBuilder;
use Tests\TestCase;

class FinancePackageBuilderTest extends TestCase
{
    public function test_payment_type_mapper_detects_advance_with_grn(): void
    {
        $mapper = app(FinanceApPaymentTypeMapper::class);

        $type = $mapper->map(
            '30% advance / 70% on delivery',
            null,
            [
                ['type' => 'purchase_order'],
                ['type' => 'grn'],
            ]
        );

        $this->assertSame('advance_payment', $type);
    }

    public function test_payment_type_mapper_detects_three_way_match(): void
    {
        $mapper = app(FinanceApPaymentTypeMapper::class);

        $type = $mapper->map(
            'Net 30',
            null,
            [
                ['type' => 'purchase_order'],
                ['type' => 'invoice'],
                ['type' => 'grn'],
            ]
        );

        $this->assertSame('three_way_match', $type);
    }

    public function test_document_type_mapping_for_finance_ap(): void
    {
        $builder = app(FinancePackageBuilder::class);
        $reflection = new \ReflectionMethod($builder, 'mapFinanceApDocumentType');
        $reflection->setAccessible(true);

        $this->assertSame('purchase_order', $reflection->invoke($builder, ProcurementDocument::TYPE_SIGNED_PO));
        $this->assertSame('invoice', $reflection->invoke($builder, ProcurementDocument::TYPE_VENDOR_INVOICE));
        $this->assertSame('pfi', $reflection->invoke($builder, ProcurementDocument::TYPE_PFI));
        $this->assertSame('waybill', $reflection->invoke($builder, ProcurementDocument::TYPE_WAYBILL));
    }
}
