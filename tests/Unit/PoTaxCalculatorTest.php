<?php

namespace Tests\Unit;

use App\Support\PoTaxCalculator;
use PHPUnit\Framework\TestCase;

class PoTaxCalculatorTest extends TestCase
{
    public function test_per_line_blank_rate_defaults_to_seven_point_five(): void
    {
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 2, 'unit_price' => 1000, 'tax_rate' => null],
        ], PoTaxCalculator::MODE_PER_LINE);

        $this->assertSame(2000.0, $calc['subtotal']);
        $this->assertSame(150.0, $calc['tax']);
        $this->assertSame(2150.0, $calc['total']);
        $this->assertSame('7.5%', $calc['lines'][0]['tax_label']);
        $this->assertSame(2150.0, $calc['lines'][0]['amount']);
    }

    public function test_per_line_zero_rate_is_not_taxed_and_mixed_rates_sum(): void
    {
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 1, 'unit_price' => 1000, 'tax_rate' => ''],
            ['quantity' => 3, 'unitPrice' => 100, 'taxRate' => 0],
            ['qty' => 1, 'unit_price' => 500, 'tax_rate' => '10'],
        ], PoTaxCalculator::MODE_PER_LINE);

        $this->assertSame(1800.0, $calc['subtotal']);
        $this->assertSame(75.0 + 0.0 + 50.0, $calc['tax']);
        $this->assertSame(1925.0, $calc['total']);
        $this->assertSame(['7.5%', '0%', '10%'], array_column($calc['lines'], 'tax_label'));
        $this->assertSame([1075.0, 300.0, 550.0], array_column($calc['lines'], 'amount'));
    }

    public function test_per_line_vat_is_rounded_once_per_rate_band(): void
    {
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 1, 'unit_price' => 0.33, 'tax_rate' => 7.5],
            ['quantity' => 1, 'unit_price' => 0.33, 'tax_rate' => 7.5],
        ], PoTaxCalculator::MODE_PER_LINE);

        // Band base 0.66 × 7.5% = 0.0495 → 0.05 (not 0.02 + 0.02).
        $this->assertSame(0.05, $calc['tax']);
        $this->assertSame(0.71, $calc['total']);
    }

    public function test_legacy_stored_amount_wins_over_rate(): void
    {
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 50],
        ], null, 7.5, 99);

        $this->assertNull($calc['mode']);
        $this->assertSame(99.0, $calc['tax']);
        $this->assertSame(1099.0, $calc['total']);
        // Legacy lines ignore any per-line rate and stay pre-VAT.
        $this->assertSame('7.5%', $calc['lines'][0]['tax_label']);
        $this->assertSame(1000.0, $calc['lines'][0]['amount']);
    }

    public function test_legacy_falls_back_to_rate_and_zero_when_unset(): void
    {
        $byRate = PoTaxCalculator::forLines([['quantity' => 1, 'unit_price' => 200]], null, '5', 0);
        $this->assertSame(10.0, $byRate['tax']);

        $none = PoTaxCalculator::forLines([['quantity' => 1, 'unit_price' => 200]], null);
        $this->assertSame(0.0, $none['tax']);
        $this->assertSame('0%', $none['lines'][0]['tax_label']);
    }

    public function test_normalise_mode_only_accepts_per_line(): void
    {
        $this->assertSame('per_line', PoTaxCalculator::normaliseMode(' PER_LINE '));
        $this->assertNull(PoTaxCalculator::normaliseMode('global'));
        $this->assertNull(PoTaxCalculator::normaliseMode(null));
    }

    public function test_percentage_discount_is_taken_before_vat_across_mixed_rates(): void
    {
        // 2000 @ 7.5% + 500 @ 0%, 10% discount → 250 off, shared 200 / 50.
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 2, 'unit_price' => 1000, 'tax_rate' => null],
            ['quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
        ], PoTaxCalculator::MODE_PER_LINE, discount: PoTaxCalculator::discount('percentage', 10));

        $this->assertSame(2500.0, $calc['subtotal']);
        $this->assertSame(250.0, $calc['discount']);
        $this->assertSame([200.0, 50.0], array_column($calc['lines'], 'discount'));
        $this->assertSame(135.0, $calc['tax']); // 1800 × 7.5%
        $this->assertSame(2385.0, $calc['total']); // 2500 − 250 + 135
        // Line display amounts keep full VAT (not discounted).
        $this->assertSame([2150.0, 500.0], array_column($calc['lines'], 'amount'));
    }

    public function test_fixed_discount_shares_add_up_and_cap_at_subtotal(): void
    {
        $calc = PoTaxCalculator::forLines([
            ['quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
            ['quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
            ['quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10],
        ], PoTaxCalculator::MODE_PER_LINE, discount: PoTaxCalculator::discount('amount', 100));

        $this->assertSame(100.0, $calc['discount']);
        $this->assertSame(100.0, round(array_sum(array_column($calc['lines'], 'discount')), 2));
        $this->assertSame(20.0, $calc['tax']);
        $this->assertSame(220.0, $calc['total']);

        $capped = PoTaxCalculator::forLines(
            [['quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]],
            PoTaxCalculator::MODE_PER_LINE,
            discount: PoTaxCalculator::discount('amount', 80),
        );
        $this->assertSame(50.0, $capped['discount']);
        $this->assertSame(0.0, $capped['total']);
    }

    public function test_discount_normalisation(): void
    {
        $this->assertNull(PoTaxCalculator::discount(null, 5));
        $this->assertNull(PoTaxCalculator::discount('percentage', 0));
        $this->assertNull(PoTaxCalculator::discount('bogus', 5));
        $this->assertSame(['type' => 'amount', 'value' => 5.0], PoTaxCalculator::discount(' Amount ', '5'));
        $this->assertSame(
            'Discount: 5% of subtotal (before VAT)',
            PoTaxCalculator::discountNote(PoTaxCalculator::discount('percentage', 5), 'NGN 10.00'),
        );
    }
}
