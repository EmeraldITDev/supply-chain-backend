<?php

namespace Tests\Unit;

use App\Services\PoNumberGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): PoNumberGenerator
    {
        return app(PoNumberGenerator::class);
    }

    public function test_supplier_token_strips_non_alphanumeric_and_preserves_case(): void
    {
        $gen = $this->generator();

        $this->assertSame('MochenzComputers', $gen->normalizeSupplierToken('Mochenz Computers'));
        $this->assertSame('AlFatahTrading', $gen->normalizeSupplierToken('Al-Fatah Trading'));
        $this->assertSame('3MNigeriaLtd', $gen->normalizeSupplierToken('3M Nigeria Ltd'));
        $this->assertSame('CoSupplies', $gen->normalizeSupplierToken('  &Co Supplies'));
    }

    public function test_supplier_token_falls_back_to_vendor_when_empty(): void
    {
        $this->assertSame('Vendor', $this->generator()->normalizeSupplierToken('   '));
        $this->assertSame('Vendor', $this->generator()->normalizeSupplierToken('!!!'));
        $this->assertSame('Vendor', $this->generator()->normalizeSupplierToken(null));
    }

    public function test_supplier_token_is_capped_at_thirty_characters(): void
    {
        $token = $this->generator()->normalizeSupplierToken(str_repeat('A', 50));
        $this->assertSame(30, mb_strlen($token));
    }

    public function test_date_part_is_ddmmyy(): void
    {
        $this->assertSame('220626', $this->generator()->formatDatePart(Carbon::create(2026, 6, 22)));
    }

    public function test_serial_increments_per_supplier_per_day(): void
    {
        $gen = $this->generator();
        $date = Carbon::create(2026, 6, 22);

        $this->assertSame('PO-220626-MochenzComputers-0001', $gen->generate('Mochenz Computers', $date));
        $this->assertSame('PO-220626-MochenzComputers-0002', $gen->generate('Mochenz Computers', $date));
    }

    public function test_serial_is_independent_per_supplier(): void
    {
        $gen = $this->generator();
        $date = Carbon::create(2026, 6, 22);

        $gen->generate('Mochenz Computers', $date);

        $this->assertSame('PO-220626-AlFatahTrading-0001', $gen->generate('Al-Fatah Trading', $date));
    }

    public function test_serial_resets_on_a_new_day(): void
    {
        $gen = $this->generator();

        $gen->generate('Mochenz Computers', Carbon::create(2026, 6, 22));
        $next = $gen->generate('Mochenz Computers', Carbon::create(2026, 6, 23));

        $this->assertSame('PO-230626-MochenzComputers-0001', $next);
    }
}
