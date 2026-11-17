<?php

namespace Tests\Feature;

use App\Models\Vendor;
use App\Services\ManualVendorOnboardingService;
use App\Services\SupplierResolutionService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class SupplierResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_matching_ignores_case_and_surrounding_whitespace(): void
    {
        $vendor = $this->createVendor('Acme Industrial Ltd', 'supplier@example.com');
        $created = false;

        $result = app(SupplierResolutionService::class)->resolveOrCreate(
            '  ACME   INDUSTRIAL LTD ',
            ' SUPPLIER@EXAMPLE.COM ',
            function (string $name, string $email) use (&$created): Vendor {
                $created = true;

                return $this->createVendor('Unexpected', 'unexpected@example.com');
            },
        );

        $this->assertSame('matched', $result['status']);
        $this->assertSame('email_and_name', $result['matched_on']);
        $this->assertSame($vendor->id, $result['vendor']->id);
        $this->assertFalse($created);
        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_ambiguous_name_requires_review_instead_of_selecting_first(): void
    {
        $this->createVendor('ECM   Transport Services', 'ecm-one@example.com');
        $this->createVendor('ECM Transport Services', 'ecm-two@example.com');
        $created = false;

        $result = app(SupplierResolutionService::class)->resolveOrCreate(
            'ECM Transport Services',
            'new@example.com',
            function () use (&$created): Vendor {
                $created = true;

                return $this->createVendor('Unexpected', 'new@example.com');
            },
        );

        $this->assertSame('review_required', $result['status']);
        $this->assertSame('supplier_identity_ambiguous', $result['reason']);
        $this->assertFalse($created);
        $this->assertSame(2, Vendor::query()->count());
    }

    public function test_email_and_name_conflict_requires_review(): void
    {
        $this->createVendor('Alpha Services', 'shared@example.com');
        $this->createVendor('Beta Services', 'beta@example.com');

        $result = app(SupplierResolutionService::class)->resolve(
            'Beta Services',
            'shared@example.com',
        );

        $this->assertSame('review_required', $result['status']);
        $this->assertNull($result['vendor']);
    }

    public function test_email_match_with_an_unknown_conflicting_name_requires_review(): void
    {
        $this->createVendor('Alpha Services', 'shared@example.com');

        $result = app(SupplierResolutionService::class)->resolve(
            'New Beta Services',
            'shared@example.com',
        );

        $this->assertSame('review_required', $result['status']);
        $this->assertNull($result['vendor']);
    }

    public function test_new_offline_supplier_is_created_when_no_match_exists(): void
    {
        $result = app(SupplierResolutionService::class)->resolveOrCreate(
            'Offline Approved Supplier',
            'offline@example.com',
            fn (string $name, string $email): Vendor => $this->createVendor($name, $email),
        );

        $this->assertSame('created', $result['status']);
        $this->assertSame('offline@example.com', $result['vendor']->email);
        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_manual_offline_onboarding_reuses_the_resolved_supplier(): void
    {
        $service = app(ManualVendorOnboardingService::class);
        $manual = [
            'name' => 'Offline Supplier Ltd',
            'email' => 'OFFLINE@EXAMPLE.COM ',
            'phone' => '08000000000',
        ];

        $first = $service->findOrCreateFromManual($manual);
        $second = $service->findOrCreateFromManual([
            ...$manual,
            'name' => ' Offline   Supplier   Ltd ',
        ]);

        $this->assertSame('created', $first['action']);
        $this->assertSame('linked_existing', $second['action']);
        $this->assertSame($first['vendor']->id, $second['vendor']->id);
        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_repeated_creation_requests_reuse_one_supplier(): void
    {
        $service = app(SupplierResolutionService::class);
        $create = fn (string $name, string $email): Vendor => $this->createVendor($name, $email);

        $first = $service->resolveOrCreate('Concurrent Supplier', 'concurrent@example.com', $create);
        $second = $service->resolveOrCreate('Concurrent Supplier', 'concurrent@example.com', $create);

        $this->assertSame('created', $first['status']);
        $this->assertSame('matched', $second['status']);
        $this->assertSame($first['vendor']->id, $second['vendor']->id);
        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_same_name_with_a_different_email_requires_review(): void
    {
        $service = app(SupplierResolutionService::class);
        $create = fn (string $name, string $email): Vendor => $this->createVendor($name, $email);

        $first = $service->resolveOrCreate('Same Supplier', 'first@example.com', $create);
        $second = $service->resolveOrCreate(' Same   Supplier ', 'second@example.com', $create);

        $this->assertSame('created', $first['status']);
        $this->assertSame('review_required', $second['status']);
        $this->assertSame(1, Vendor::query()->count());
    }

    public function test_mysql_lock_timeout_releases_any_lock_already_acquired(): void
    {
        $connection = $this->mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->times(3)->andReturnUsing(function (string $sql) {
            static $getLockCalls = 0;
            if (str_contains($sql, 'GET_LOCK')) {
                $getLockCalls++;

                return (object) ['acquired' => $getLockCalls === 1 ? 1 : 0];
            }

            return (object) ['released' => 1];
        });
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $method = new ReflectionMethod(SupplierResolutionService::class, 'withIdentityLocks');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke(
            app(SupplierResolutionService::class),
            'supplier name',
            'supplier@example.com',
            static fn () => null,
        );
    }

    private function createVendor(string $name, string $email): Vendor
    {
        return Vendor::create([
            'vendor_id' => 'V-'.strtoupper(substr(md5($email.$name), 0, 10)),
            'name' => $name,
            'category' => 'General',
            'status' => 'Active',
            'email' => $email,
        ]);
    }
}
