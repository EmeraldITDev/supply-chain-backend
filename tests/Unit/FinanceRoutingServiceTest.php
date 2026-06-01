<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Services\Finance\FinanceRoutingService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Tests\TestCase;

class FinanceRoutingServiceTest extends TestCase
{
    public function test_legacy_cohort_before_cutover(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $service = app(FinanceRoutingService::class);

        $legacy = new MRF(['created_at' => Carbon::parse('2026-05-15')]);
        $modern = new MRF(['created_at' => Carbon::parse('2026-06-15')]);

        $this->assertSame('legacy_internal', $service->financeRoute($legacy));
        $this->assertSame('finance_ap', $service->financeRoute($modern));
    }

    public function test_finance_ap_ready_uses_workflow_state_not_status_finance(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        $service = app(FinanceRoutingService::class);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-06-01'),
            'workflow_state' => WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            'status' => 'signed',
            'signed_po_url' => 'https://example.com/po.pdf',
        ]);

        $this->assertTrue($service->isFinanceApFinanceReady($mrf));
        $this->assertFalse($service->isLegacyFinanceReady($mrf));
    }

    public function test_legacy_ready_uses_status_finance(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $service = app(FinanceRoutingService::class);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-05-01'),
            'status' => 'finance',
            'signed_po_url' => 'https://example.com/po.pdf',
        ]);

        $this->assertTrue($service->isLegacyFinanceReady($mrf));
        $this->assertFalse($service->usesFinanceAp($mrf));
    }
}
