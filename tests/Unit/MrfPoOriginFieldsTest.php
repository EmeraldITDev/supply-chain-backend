<?php

namespace Tests\Unit;

use App\Models\MRF;
use PHPUnit\Framework\TestCase;

class MrfPoOriginFieldsTest extends TestCase
{
    public function test_po_origin_api_fields_include_linked_po_id(): void
    {
        $mrf = new MRF([
            'source' => 'po_generated',
            'is_po_linked' => true,
            'linked_po_id' => 'PO-2026-0615093045-A1B2C',
        ]);

        $fields = $mrf->poOriginApiFields();

        $this->assertSame('po_generated', $fields['source']);
        $this->assertTrue($fields['is_po_linked']);
        $this->assertTrue($fields['isPoLinked']);
        $this->assertSame('PO-2026-0615093045-A1B2C', $fields['linked_po_id']);
        $this->assertSame('PO-2026-0615093045-A1B2C', $fields['linkedPoId']);
    }

    public function test_infer_po_generated_from_manual_po_justification(): void
    {
        $this->assertTrue(MRF::inferPoGeneratedFromJustification(
            'Manual PO created without RFQ — vendor and pricing captured directly on the purchase order.'
        ));
        $this->assertFalse(MRF::inferPoGeneratedFromJustification('Standard procurement request.'));
    }

    public function test_po_form_api_fields_falls_back_to_linked_po_id_when_po_number_missing(): void
    {
        $mrf = new MRF([
            'source' => 'po_generated',
            'is_po_linked' => true,
            'linked_po_id' => 'PO-2026-0615093045-A1B2C',
            'po_number' => null,
        ]);

        $fields = $mrf->poFormApiFields();

        $this->assertSame('PO-2026-0615093045-A1B2C', $fields['po_number']);
        $this->assertSame('PO-2026-0615093045-A1B2C', $fields['poNumber']);
    }
}
