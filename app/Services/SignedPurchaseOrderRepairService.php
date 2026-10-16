<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\ProcurementDocument;
use Illuminate\Support\Facades\Log;

/**
 * Puts purchase orders that were already signed back into the signed state
 * when a later failure left status/workflow saying "SCD signature pending".
 */
class SignedPurchaseOrderRepairService
{
    /**
     * @return int Number of purchase orders restored
     */
    public function repair(): int
    {
        $restored = 0;

        MRF::query()
            ->where(function ($pending) {
                $pending->whereIn('status', ['awaiting_scd_signature', 'pending_scd_signature', 'supply_chain'])
                    ->orWhereIn('workflow_state', [
                        WorkflowStateService::STATE_PO_GENERATED,
                        WorkflowStateService::STATE_PENDING_SCD_SIGNATURE,
                    ]);
            })
            ->where(function ($revision) {
                $revision->whereNull('workflow_state')
                    ->orWhere('workflow_state', '!=', WorkflowStateService::STATE_PENDING_REVISION);
            })
            ->where(function ($revision) {
                $revision->whereNull('status')
                    ->orWhereRaw('LOWER(status) != ?', [WorkflowStateService::STATE_PENDING_REVISION]);
            })
            ->orderBy('id')
            ->chunkById(100, function ($mrfs) use (&$restored) {
                foreach ($mrfs as $mrf) {
                    if ($this->restore($mrf)) {
                        $restored++;
                    }
                }
            });

        Log::info('Restored signed purchase orders that were showing as signature pending', [
            'restored' => $restored,
        ]);

        return $restored;
    }

    public function restore(MRF $mrf): bool
    {
        if ($this->isOpenRevision($mrf)) {
            return false;
        }

        $signedUrl = $mrf->signed_po_url;
        $signedAt = $mrf->po_signed_at;

        if (! WorkflowStateService::hasRecordedSignature($signedUrl, $signedAt)) {
            $document = ProcurementDocument::query()
                ->where('mrf_id', $mrf->id)
                ->where('type', ProcurementDocument::TYPE_SIGNED_PO)
                ->where('is_active', true)
                ->latest('uploaded_at')
                ->first();

            if ($document && filled($document->file_url)) {
                $signedUrl = $document->file_url;
                $signedAt = $signedAt ?? $document->uploaded_at ?? now();
            } elseif ($this->latestSignatureActionIsSigned($mrf)) {
                $signedAt = $signedAt ?? now();
            } else {
                return false;
            }
        }

        $presented = WorkflowStateService::presentedSignatureState(
            $mrf->status,
            $mrf->workflow_state,
            $signedUrl,
            $signedAt,
        );

        $changes = [];
        if ((string) $presented['status'] !== (string) $mrf->status) {
            $changes['status'] = $presented['status'];
        }
        if ((string) $presented['workflow_state'] !== (string) $mrf->workflow_state) {
            $changes['workflow_state'] = $presented['workflow_state'];
        }
        if (blank($mrf->signed_po_url) && filled($signedUrl)) {
            $changes['signed_po_url'] = $signedUrl;
            $changes['signed_po_share_url'] = $signedUrl;
        }
        if (blank($mrf->po_signed_at) && filled($signedAt)) {
            $changes['po_signed_at'] = $signedAt;
        }
        if (($presented['workflow_state'] ?? null) === WorkflowStateService::STATE_PO_SIGNED
            && strtolower((string) ($mrf->current_stage ?? '')) !== 'finance') {
            $changes['current_stage'] = 'finance';
        }

        if ($changes === []) {
            return false;
        }

        $mrf->update($changes);

        Log::info('Restored signed purchase order out of the SCD signature queue', [
            'mrf_id' => $mrf->mrf_id,
            'changes' => array_keys($changes),
        ]);

        return true;
    }

    private function isOpenRevision(MRF $mrf): bool
    {
        $status = strtolower(trim((string) ($mrf->status ?? '')));
        $state = strtolower(trim((string) ($mrf->workflow_state ?? '')));

        return $status === WorkflowStateService::STATE_PENDING_REVISION
            || $state === WorkflowStateService::STATE_PENDING_REVISION;
    }

    private function latestSignatureActionIsSigned(MRF $mrf): bool
    {
        $latest = MRFApprovalHistory::query()
            ->where('mrf_id', $mrf->id)
            ->whereIn('action', ['signed_po', 'rejected_po', 'po_unlocked_for_edit', 'po_submitted_for_resign'])
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->action === 'signed_po';
    }
}
