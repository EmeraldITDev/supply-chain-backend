<?php

namespace Tests\Unit;

use App\Models\Vendor;
use App\Services\ManualVendorOnboardingService;
use Tests\TestCase;

class ManualVendorOnboardingServiceTest extends TestCase
{
    public function test_normalize_name_collapses_whitespace(): void
    {
        $this->assertSame('acme industrial ltd', Vendor::normalizeName('  Acme   Industrial   Ltd  '));
    }

    public function test_profile_complete_requires_business_fields(): void
    {
        $service = app(ManualVendorOnboardingService::class);

        $incomplete = new Vendor([
            'category' => 'General',
            'address' => '',
            'tax_id' => '',
            'website' => '',
            'profile_completed' => false,
        ]);

        $this->assertFalse($service->isProfileComplete($incomplete));

        $complete = new Vendor([
            'category' => 'Equipment',
            'address' => '12 Marina',
            'tax_id' => 'TIN-123',
            'website' => 'https://acme.com',
            'profile_completed' => false,
        ]);

        $this->assertTrue($service->isProfileComplete($complete));
    }

    public function test_lookup_returns_null_when_no_match(): void
    {
        $service = app(ManualVendorOnboardingService::class);

        $this->assertNull($service->lookup('definitely-not-a-vendor@example.invalid', 'No Such Company XYZ'));
    }
}
