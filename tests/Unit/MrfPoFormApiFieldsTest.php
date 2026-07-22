<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\Vendor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MrfPoFormApiFieldsTest extends TestCase
{
    #[Test]
    public function it_exposes_selected_vendor_fields_for_po_form_payload(): void
    {
        $mrf = new MRF();
        $mrf->forceFill([
            'po_number' => 'PO-001',
            'currency' => 'NGN',
        ]);

        $vendor = new Vendor();
        $vendor->forceFill([
            'vendor_id' => 'V123',
            'name' => 'FEMBOSCO ENGINEERING LIMITED',
            'email' => 'ops@example.com',
            'phone' => '08000000000',
            'address' => 'Lagos',
            'contact_person' => 'Ada',
        ]);

        $mrf->setRelation('selectedVendor', $vendor);

        $payload = $mrf->poFormApiFields();

        $this->assertSame('V123', $payload['selectedVendorId']);
        $this->assertSame('V123', $payload['selected_vendor']['vendor_id']);
        $this->assertSame('FEMBOSCO ENGINEERING LIMITED', $payload['selectedVendor']['name']);
        $this->assertSame('ops@example.com', $payload['selected_vendor']['email']);
    }
}
