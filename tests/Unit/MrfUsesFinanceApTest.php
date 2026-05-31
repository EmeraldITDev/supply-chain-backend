<?php

namespace Tests\Unit;

use App\Models\MRF;
use Carbon\Carbon;
use Tests\TestCase;

class MrfUsesFinanceApTest extends TestCase
{
    public function test_mrf_before_cutover_uses_internal_finance(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-05-15'),
        ]);

        $this->assertFalse(mrfUsesFinanceAp($mrf));
    }

    public function test_mrf_on_or_after_cutover_uses_finance_ap(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-06-01 09:00:00'),
        ]);

        $this->assertTrue(mrfUsesFinanceAp($mrf));
    }

    public function test_missing_cutover_date_defaults_to_internal_finance(): void
    {
        config(['finance_ap.cutover_date' => null]);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-06-15'),
        ]);

        $this->assertFalse(mrfUsesFinanceAp($mrf));
    }
}
