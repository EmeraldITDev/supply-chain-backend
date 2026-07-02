<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Support\PurchaseOrderCurrency;
use PHPUnit\Framework\TestCase;

class PurchaseOrderCurrencyTest extends TestCase
{
    public function test_normalize_defaults_to_ngn(): void
    {
        $this->assertSame('NGN', PurchaseOrderCurrency::normalize(null));
        $this->assertSame('NGN', PurchaseOrderCurrency::normalize(''));
        $this->assertSame('NGN', PurchaseOrderCurrency::normalize('EUR'));
    }

    public function test_normalize_accepts_usd_case_insensitive(): void
    {
        $this->assertSame('USD', PurchaseOrderCurrency::normalize('usd'));
        $this->assertSame('USD', PurchaseOrderCurrency::normalize(' USD '));
    }

    public function test_mrf_currency_api_fields(): void
    {
        $mrf = new MRF(['currency' => 'USD']);

        $this->assertSame(['currency' => 'USD'], $mrf->currencyApiFields());
    }
}
