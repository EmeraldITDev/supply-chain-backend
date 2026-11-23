<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Services\PurchaseOrderRevisionService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time typo correction for a single draft-editable purchase order title.
 *
 * Targets only:
 *   PO:  PO-231126-PRECISIONGARAGE-0004
 *   MRF: MRF-EMERALD-PRC-EQP-2026-264
 *   old → new: MUS-295KM … → MUS-275KM …
 *
 * Default is dry-run. Pass --apply to persist. Does not regenerate PDFs or
 * touch QuickBooks; refused when the PO is no longer draft-editable.
 *
 * Usage:
 *   php artisan scm:correct-po-title
 *   php artisan scm:correct-po-title --apply
 */
class CorrectPoTitle extends Command
{
    public const TARGET_PO_NUMBER = 'PO-231126-PRECISIONGARAGE-0004';

    public const TARGET_MRF_ID = 'MRF-EMERALD-PRC-EQP-2026-264';

    public const EXPECTED_OLD_TITLE = 'MUS-295KM FORD TRANSIT SERVICING';

    public const NEW_TITLE = 'MUS-275KM FORD TRANSIT SERVICING';

    protected $signature = 'scm:correct-po-title
                            {--apply : Persist the title change (default is dry-run)}';

    protected $description = 'One-time correction of PO title MUS-295KM → MUS-275KM for PO-231126-PRECISIONGARAGE-0004 (dry-run by default)';

    public function handle(PurchaseOrderRevisionService $revisions): int
    {
        $apply = (bool) $this->option('apply');

        $mrf = MRF::query()
            ->where('mrf_id', self::TARGET_MRF_ID)
            ->where('po_number', self::TARGET_PO_NUMBER)
            ->first();

        if (! $mrf) {
            $this->error(sprintf(
                'No MRF matches both identifiers (po=%s, mrf=%s). Refusing.',
                self::TARGET_PO_NUMBER,
                self::TARGET_MRF_ID
            ));

            return self::FAILURE;
        }

        $currentTitle = trim((string) ($mrf->title ?? ''));

        $this->table(
            ['Field', 'Value'],
            [
                ['po_number', $mrf->po_number ?: '—'],
                ['mrf_id', $mrf->mrf_id],
                ['title (authoritative: m_r_f_s.title)', $currentTitle !== '' ? $currentTitle : '—'],
                ['status', $mrf->status ?: '—'],
                ['workflow_state', $mrf->workflow_state ?: '—'],
                ['revision_number', (string) ((int) ($mrf->revision_number ?? 0))],
                ['po_draft_saved_at', $mrf->po_draft_saved_at?->toDateTimeString() ?? '—'],
                ['unsigned_po_url', filled($mrf->unsigned_po_url) ? 'set' : 'empty'],
                ['signed_po_url', filled($mrf->signed_po_url) ? 'set' : 'empty'],
                ['po_signed_at', $mrf->po_signed_at?->toDateTimeString() ?? '—'],
                ['po_forwarded_to_vendor_at', $mrf->po_forwarded_to_vendor_at?->toDateTimeString() ?? '—'],
                ['quickbooks_po_id', filled($mrf->quickbooks_po_id) ? (string) $mrf->quickbooks_po_id : '—'],
                ['quickbooks_synced_at', $mrf->quickbooks_synced_at?->toDateTimeString() ?? '—'],
                ['quickbooks_sync_status', filled($mrf->quickbooks_sync_status) ? (string) $mrf->quickbooks_sync_status : '—'],
                ['is_draft_editable', $revisions->isDraftEditable($mrf) ? 'yes' : 'no'],
                ['mode', $apply ? 'APPLY' : 'DRY-RUN'],
            ]
        );

        if ($currentTitle === self::NEW_TITLE) {
            $this->info('Title already corrected. Nothing to change (idempotent).');
            Log::info('scm:correct-po-title already applied', [
                'po_number' => self::TARGET_PO_NUMBER,
                'mrf_id' => self::TARGET_MRF_ID,
                'title' => $currentTitle,
                'apply' => $apply,
            ]);

            return self::SUCCESS;
        }

        if ($currentTitle !== self::EXPECTED_OLD_TITLE) {
            $this->error('Existing title does not match the expected old value. Refusing.');
            $this->line('  expected: '.self::EXPECTED_OLD_TITLE);
            $this->line('  actual:   '.($currentTitle !== '' ? $currentTitle : '(empty)'));

            return self::FAILURE;
        }

        $blockers = $this->lifecycleBlockers($mrf);
        $editable = $revisions->isDraftEditable($mrf);

        if ($blockers !== []) {
            $this->error(sprintf(
                'PO is %s — direct title modification refused. Use the normal unlock / revision workflow.',
                implode(', ', $blockers)
            ));
            Log::warning('scm:correct-po-title refused: lifecycle blockers', [
                'po_number' => self::TARGET_PO_NUMBER,
                'mrf_id' => self::TARGET_MRF_ID,
                'blockers' => $blockers,
                'status' => $mrf->status,
                'workflow_state' => $mrf->workflow_state,
                'title' => $currentTitle,
            ]);

            return self::FAILURE;
        }

        if (! $editable) {
            $this->error('PO is not draft-editable. Direct title modification refused. Use the normal unlock / revision workflow.');
            Log::warning('scm:correct-po-title refused: not draft-editable', [
                'po_number' => self::TARGET_PO_NUMBER,
                'mrf_id' => self::TARGET_MRF_ID,
                'status' => $mrf->status,
                'workflow_state' => $mrf->workflow_state,
                'title' => $currentTitle,
            ]);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Would update m_r_f_s.title: "%s" → "%s"',
            self::EXPECTED_OLD_TITLE,
            self::NEW_TITLE
        ));

        if (! $apply) {
            $this->warn('Dry run only — no records were changed. Re-run with --apply to persist.');
            Log::info('scm:correct-po-title dry-run', [
                'po_number' => self::TARGET_PO_NUMBER,
                'mrf_id' => self::TARGET_MRF_ID,
                'old_title' => self::EXPECTED_OLD_TITLE,
                'new_title' => self::NEW_TITLE,
                'mrf_row_id' => $mrf->id,
            ]);

            return self::SUCCESS;
        }

        // Conditional update: only this row, only while title is still the expected old value.
        $updated = MRF::query()
            ->whereKey($mrf->id)
            ->where('mrf_id', self::TARGET_MRF_ID)
            ->where('po_number', self::TARGET_PO_NUMBER)
            ->where('title', self::EXPECTED_OLD_TITLE)
            ->update(['title' => self::NEW_TITLE]);

        if ($updated !== 1) {
            $this->error('Update affected 0 rows (title or identifiers changed concurrently). No change applied.');
            Log::warning('scm:correct-po-title apply missed', [
                'po_number' => self::TARGET_PO_NUMBER,
                'mrf_id' => self::TARGET_MRF_ID,
                'rows' => $updated,
            ]);

            return self::FAILURE;
        }

        $fresh = $mrf->fresh();
        $this->info(sprintf(
            'Updated m_r_f_s.title for %s / %s: "%s" → "%s"',
            self::TARGET_PO_NUMBER,
            self::TARGET_MRF_ID,
            self::EXPECTED_OLD_TITLE,
            $fresh?->title
        ));

        Log::info('scm:correct-po-title applied', [
            'po_number' => self::TARGET_PO_NUMBER,
            'mrf_id' => self::TARGET_MRF_ID,
            'mrf_row_id' => $mrf->id,
            'old_title' => self::EXPECTED_OLD_TITLE,
            'new_title' => self::NEW_TITLE,
            'verified_title' => $fresh?->title,
        ]);

        return self::SUCCESS;
    }

    /**
     * Lifecycle flags that require the normal PO revision workflow.
     *
     * @return list<string>
     */
    private function lifecycleBlockers(MRF $mrf): array
    {
        $blockers = [];
        $status = strtolower(trim((string) ($mrf->status ?? '')));
        $state = strtolower(trim((string) ($mrf->workflow_state ?? '')));

        $hasUnsigned = trim((string) ($mrf->unsigned_po_url ?? '')) !== '';
        $hasSigned = trim((string) ($mrf->signed_po_url ?? '')) !== '' || $mrf->po_signed_at !== null;

        if ($hasUnsigned
            || in_array($status, ['awaiting_scd_signature', 'pending_scd_signature', 'pending_po_upload'], true)
            || in_array($state, [
                WorkflowStateService::STATE_PO_GENERATED,
                WorkflowStateService::STATE_PENDING_SCD_SIGNATURE,
            ], true)
        ) {
            $blockers[] = 'submitted';
        }

        $approvedStates = array_merge(
            [WorkflowStateService::STATE_PO_SIGNED],
            MRF::poCompletedWorkflowStates()
        );
        if (in_array($status, ['signed', 'approved'], true)
            || in_array($state, $approvedStates, true)
            || $mrf->finance_approved_at !== null
            || $mrf->invoice_approved_at !== null
            || $mrf->payment_approved_at !== null
        ) {
            $blockers[] = 'approved';
        }

        if ($hasSigned) {
            $blockers[] = 'signed';
        }

        if ($mrf->po_forwarded_to_vendor_at !== null) {
            $blockers[] = 'issued';
        }

        if (filled($mrf->quickbooks_po_id)
            || $mrf->quickbooks_synced_at !== null
            || filled($mrf->quickbooks_sync_status)
        ) {
            $blockers[] = 'synced';
        }

        return array_values(array_unique($blockers));
    }
}
