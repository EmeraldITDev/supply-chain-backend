<?php

namespace Tests\Unit;

use App\Models\Vendor;
use App\Services\Finance\FinanceApVendorSnapshotBuilder;
use Tests\TestCase;

class FinanceApVendorSnapshotBuilderTest extends TestCase
{
    public function test_snapshot_includes_scm_source_and_identity_fields(): void
    {
        $vendor = new Vendor([
            'id' => 42,
            'vendor_id' => 'V023',
            'name' => 'Mochenz Computers',
            'status' => 'Active',
            'category' => 'Equipment',
            'email' => 'sales@mochenz.com',
            'phone' => '+2348000000000',
            'tax_id' => 'TIN-123',
            'address' => '12 Marina',
            'city' => 'Lagos',
            'state' => 'LA',
            'contact_person' => 'Jane Doe',
            'contact_person_email' => 'jane@mochenz.com',
            'bank_name' => 'GTBank',
            'account_name' => 'Mochenz Computers',
            'account_number' => '0123456789',
            'profile_completed' => true,
        ]);

        $snapshot = app(FinanceApVendorSnapshotBuilder::class)->toArray($vendor);

        $this->assertSame('scm', $snapshot['source']);
        $this->assertSame(42, $snapshot['scmVendorId']);
        $this->assertSame('V023', $snapshot['vendorCode']);
        $this->assertSame('Mochenz Computers', $snapshot['name']);
        $this->assertSame('TIN-123', $snapshot['taxId']);
        $this->assertSame('GTBank', $snapshot['bankName']);
        $this->assertSame('Mochenz Computers', $snapshot['accountName']);
        $this->assertSame('0123456789', $snapshot['accountNumber']);
        $this->assertSame(42, $snapshot['scm_vendor_id']);
        $this->assertSame('V023', $snapshot['vendor_code']);
        $this->assertArrayHasKey('snapshotAt', $snapshot);
    }
}
