<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorDirectoryKycStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $vendorId, string $status, bool $profileCompleted = true): Vendor
    {
        return Vendor::create([
            'vendor_id' => $vendorId,
            'name' => $vendorId,
            'category' => 'General',
            'status' => $status,
            'email' => strtolower($vendorId).'@example.com',
            'profile_completed' => $profileCompleted,
        ]);
    }

    public function test_list_and_detail_report_the_status_the_scm_portal_shows(): void
    {
        $this->makeVendor('V-ACTIVE', 'Active');
        $this->makeVendor('V-MANUAL', 'Active', false);
        $this->makeVendor('V-SUSPEND', 'Suspended');
        Sanctum::actingAs(User::factory()->create());

        $rows = collect($this->getJson('/api/vendors')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('Verified', $rows['V-ACTIVE']['kyc_status']);
        $this->assertSame('Verified', $rows['V-MANUAL']['kyc_status']);
        $this->assertSame('Verified', $rows['V-SUSPEND']['kyc_status']);

        $this->getJson('/api/vendors/V-SUSPEND')->assertOk()->assertJsonPath('kyc_status', 'Verified');
    }
}
