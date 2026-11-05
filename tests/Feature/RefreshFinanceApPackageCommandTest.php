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

    public function test_unlinked_case_requires_verified_finance_ap_ownership(): void
    {
        config([
            'finance_ap.cutover_date' => '2026-01-01',
            'finance_ap.enabled' => true,
        ]);

        $transactionId = (string) Str::uuid();
        $mrf = MRF::create([
            'mrf_id' => 'MRF-UNLINKED-TEST',
            'title' => 'Unlinked refresh test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-UNLINKED-TEST',
            'finance_ap_case_id' => null,
            'po_payment_terms' => '70% ADVANCE PAYMENT, 30% UPON JOB DELIVERY',
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldReceive('verifyCaseOwnership')
            ->once()
            ->with($mrf, '99', 'PO-UNLINKED-TEST')
            ->andReturn(['available' => true, 'verified' => true, 'reason' => 'verified']);
        $integration->shouldReceive('buildPackage')
            ->once()
            ->with($mrf, null, 'authorized_refresh', 2, false)
            ->andReturn(['header' => [], 'paymentPlan' => ['stages' => []]]);
        $integration->shouldNotReceive('pushPackageAtVersion');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-UNLINKED-TEST',
            '--finance-case-id' => '99',
            '--finance-version' => '1',
        ])->assertExitCode(0);

        $this->assertNull($mrf->fresh()->finance_ap_case_id);
    }

    public function test_unlinked_case_rejects_unverified_ownership(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01', 'finance_ap.enabled' => true]);

        $transactionId = (string) Str::uuid();
        $mrf = MRF::create([
            'mrf_id' => 'MRF-MISMATCH-TEST',
            'title' => 'Mismatch test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-MISMATCH-TEST',
            'finance_ap_case_id' => null,
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldReceive('verifyCaseOwnership')
            ->once()
            ->andReturn(['available' => true, 'verified' => false, 'reason' => 'mismatch']);
        $integration->shouldNotReceive('buildPackage');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-MISMATCH-TEST',
            '--finance-case-id' => '99',
            '--finance-version' => '1',
        ])->assertExitCode(1);
    }

    public function test_unlinked_case_fails_closed_when_read_only_verification_is_unavailable(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01', 'finance_ap.enabled' => true]);

        $transactionId = (string) Str::uuid();
        $mrf = MRF::create([
            'mrf_id' => 'MRF-VERIFY-UNAVAILABLE',
            'title' => 'Verification unavailable test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-VERIFY-UNAVAILABLE',
            'finance_ap_case_id' => null,
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldReceive('verifyCaseOwnership')
            ->once()
            ->andReturn(['available' => false, 'verified' => false, 'reason' => 'endpoint unavailable']);
        $integration->shouldNotReceive('buildPackage');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-VERIFY-UNAVAILABLE',
            '--finance-case-id' => '99',
            '--finance-version' => '1',
        ])->assertExitCode(1);
    }

    public function test_invalid_current_version_is_rejected_before_package_build(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01', 'finance_ap.enabled' => true]);

        $transactionId = (string) Str::uuid();
        MRF::create([
            'mrf_id' => 'MRF-VERSION-TEST',
            'title' => 'Version test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-VERSION-TEST',
            'finance_ap_case_id' => '99',
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldNotReceive('buildPackage');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-VERSION-TEST',
            '--finance-case-id' => '99',
            '--finance-version' => '0',
        ])->assertExitCode(1);
    }

    public function test_transaction_and_po_must_match_before_unlinked_case_verification(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01', 'finance_ap.enabled' => true]);

        $transactionId = (string) Str::uuid();
        MRF::create([
            'mrf_id' => 'MRF-IDENTITY-TEST',
            'title' => 'Identity test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 100,
            'scm_transaction_id' => $transactionId,
            'po_number' => 'PO-REAL',
            'finance_ap_case_id' => null,
        ]);

        $integration = $this->mock(FinanceIntegrationService::class);
        $integration->shouldNotReceive('verifyCaseOwnership');

        $this->artisan('finance-ap:refresh-package', [
            'scm_transaction_id' => $transactionId,
            '--po-number' => 'PO-WRONG',
            '--finance-case-id' => '99',
            '--finance-version' => '1',
        ])->assertExitCode(1);
    }
}
