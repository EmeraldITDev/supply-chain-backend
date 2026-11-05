<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\User;
use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshFinanceApPackageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_requires_exact_identity_and_does_not_send(): void
    {
        config([
            'finance_ap.cutover_date' => '2026-01-01',
            'finance_ap.enabled' => true,
        ]);

        $transactionId = (string) Str::uuid();
        $mrf = MRF::create([
            'mrf_id' => 'MRF-REFRESH-TEST',
            'title' => 'Refresh test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-REFRESH-TEST',
            'finance_ap_case_id' => '99',
            'po_payment_terms' => '70% ADVANCE PAYMENT, 30% UPON JOB DELIVERY',
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldReceive('buildPackage')
            ->once()
            ->with($mrf, null, 'authorized_refresh', 2, false)
            ->andReturn([
                'header' => ['paymentTerms' => $mrf->po_payment_terms],
                'paymentPlan' => [
                    'originalApprovedTerms' => $mrf->po_payment_terms,
                    'sourcePaymentTerms' => $mrf->po_payment_terms,
                    'classificationStatus' => 'requires_finance_ap_classification',
                    'stages' => [],
                ],
            ]);
        $integration->shouldNotReceive('pushPackageAtVersion');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-REFRESH-TEST',
            '--finance-case-id' => '99',
            '--finance-version' => '1',
        ])->assertExitCode(0);
    }
}
