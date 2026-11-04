<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Console\Command;

class RefreshFinanceApPackage extends Command
{
    protected $signature = 'finance-ap:refresh-package
        {scm_transaction_id : Exact SCM transaction UUID}
        {--po-number= : Exact SCM PO number}
        {--finance-case-id= : Existing Finance AP case ID}
        {--finance-version= : Current Finance AP package version}
        {--execute : Send the explicitly authorized refresh}';

    protected $description = 'Dry-run or explicitly send a versioned Finance AP package refresh';

    public function handle(FinanceIntegrationService $integration): int
    {
        $transactionId = (string) $this->argument('scm_transaction_id');
        $poNumber = (string) $this->option('po-number');
        $caseId = (string) $this->option('finance-case-id');
        $currentVersion = filter_var($this->option('finance-version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($poNumber === '' || $caseId === '' || $currentVersion === false) {
            $this->error('Require --po-number, --finance-case-id, and --finance-version from Finance AP.');

            return self::FAILURE;
        }

        $mrf = MRF::query()->where('scm_transaction_id', $transactionId)->first();
        if (! $mrf || $mrf->po_number !== $poNumber || (string) $mrf->finance_ap_case_id !== $caseId) {
            $this->error('SCM transaction, PO number, and Finance AP case do not match one record.');

            return self::FAILURE;
        }

        if (! mrfUsesFinanceAp($mrf)) {
            $this->error('The SCM record is not routed through Finance AP.');

            return self::FAILURE;
        }

        $nextVersion = $currentVersion + 1;
        $package = $integration->buildPackage($mrf, null, 'authorized_refresh', $nextVersion, false);

        $this->table([
            'SCM transaction', 'SCM PO', 'Finance AP case', 'Current version', 'Proposed version',
        ], [[
            $transactionId, $mrf->po_number, $caseId, $currentVersion, $nextVersion,
        ]]);
        $this->line('paymentTerms: '.($package['header']['paymentTerms'] ?? 'null'));
        $this->line('paymentPlan.originalApprovedTerms: '.($package['paymentPlan']['originalApprovedTerms'] ?? 'null'));
        $this->line('paymentPlan.sourcePaymentTerms: '.($package['paymentPlan']['sourcePaymentTerms'] ?? 'null'));
        $this->line('paymentPlan.classificationStatus: '.($package['paymentPlan']['classificationStatus'] ?? 'null'));
        $this->line('paymentPlan.stages: '.count($package['paymentPlan']['stages'] ?? []));

        if (! $this->option('execute')) {
            $this->info('Dry run only. Nothing was sent. Add --execute after Finance AP deployment validation.');

            return self::SUCCESS;
        }

        if (! $integration->pushPackageAtVersion($mrf, $nextVersion)) {
            $this->error('Finance AP package refresh failed; no automatic retry was attempted.');

            return self::FAILURE;
        }

        $this->info("Finance AP package version {$nextVersion} sent successfully.");

        return self::SUCCESS;
    }
}
