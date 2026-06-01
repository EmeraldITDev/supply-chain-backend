<?php

namespace Tests\Unit;

use App\Services\FinanceAp\FinanceApReportingService;
use Tests\TestCase;

class FinanceApReportingServiceTest extends TestCase
{
    public function test_summary_reports_routing_not_configured_when_cutover_missing(): void
    {
        config(['finance_ap.cutover_date' => null]);

        $summary = app(FinanceApReportingService::class)->summary(null, null);

        $this->assertFalse($summary['routingConfigured']);
        $this->assertNull($summary['cutoverDate']);
        $this->assertSame(0, $summary['totals']['financeApMrfs']);
    }

    public function test_summary_includes_routing_context_when_cutover_set(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        $summary = app(FinanceApReportingService::class)->summary(null, null);

        $this->assertTrue($summary['routingConfigured']);
        $this->assertSame('2026-01-01', $summary['cutoverDate']);
        $this->assertArrayHasKey('totals', $summary);
    }
}
