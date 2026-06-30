<?php

namespace App\Services\Finance;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\Vendor;
use App\Services\WorkflowStateService;
use Illuminate\Support\Collection;

/**
 * Lists SCM purchase orders that are open for Finance AP payment initiation.
 */
class FinanceApOpenPurchaseOrderService
{
    /** @var list<string> */
    public const PAYABLE_WORKFLOW_STATES = [
        WorkflowStateService::STATE_PO_SIGNED,
        WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
        WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
        WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
        WorkflowStateService::STATE_FINANCE_IN_REVIEW,
        WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
        WorkflowStateService::STATE_PAYMENT_PROCESSED,
        WorkflowStateService::STATE_INVOICE_APPROVED,
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function listForVendor(int $scmVendorId): array
    {
        if (! Vendor::query()->whereKey($scmVendorId)->exists()) {
            return [];
        }

        return MRF::query()
            ->with(['paymentSchedule.milestones', 'selectedVendor:id,vendor_id,name'])
            ->where('selected_vendor_id', $scmVendorId)
            ->whereNotNull('po_number')
            ->where('po_number', '!=', '')
            ->whereIn('workflow_state', self::PAYABLE_WORKFLOW_STATES)
            ->orderByDesc('po_signed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MRF $mrf) => $this->formatMrf($mrf))
            ->filter(fn (array $row) => ($row['remainingBalance'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMrf(MRF $mrf): array
    {
        $schedule = $mrf->paymentSchedule;
        $poTotal = $this->resolvePoTotal($mrf, $schedule);
        $paid = $this->resolvePaidAmount($schedule?->milestones ?? collect());
        $remaining = max(0, round($poTotal - $paid, 2));

        return [
            'scmVendorId' => (int) $mrf->selected_vendor_id,
            'scm_vendor_id' => (int) $mrf->selected_vendor_id,
            'scmPoNumber' => $mrf->po_number,
            'scm_po_number' => $mrf->po_number,
            'scmTransactionId' => $mrf->scm_transaction_id,
            'scm_transaction_id' => $mrf->scm_transaction_id,
            'mrfId' => $mrf->mrf_id,
            'mrf_id' => $mrf->mrf_id,
            'formattedId' => $mrf->formatted_id,
            'formatted_id' => $mrf->formatted_id,
            'workflowState' => $mrf->workflow_state,
            'workflow_state' => $mrf->workflow_state,
            'status' => $this->mapFinanceApStatus($mrf->workflow_state),
            'totalAmount' => $poTotal,
            'total_amount' => $poTotal,
            'remainingBalance' => $remaining,
            'remaining_balance' => $remaining,
            'cumulativeSpend' => $paid,
            'cumulative_spend' => $paid,
            'currency' => $mrf->currency ?? 'NGN',
            'description' => $mrf->title,
            'financeApCaseId' => $mrf->finance_ap_case_id,
            'finance_ap_case_id' => $mrf->finance_ap_case_id,
        ];
    }

    private function resolvePoTotal(MRF $mrf, $schedule): float
    {
        $quotation = $mrf->selectedQuotation();

        if ($quotation && (float) $quotation->total_amount > 0) {
            return (float) $quotation->total_amount;
        }

        if ($schedule && $schedule->milestones->isNotEmpty()) {
            return (float) $schedule->milestones->sum(fn (PaymentMilestone $m) => (float) ($m->amount ?? 0));
        }

        return (float) ($mrf->estimated_cost ?? 0);
    }

    /**
     * @param  Collection<int, PaymentMilestone>  $milestones
     */
    private function resolvePaidAmount(Collection $milestones): float
    {
        return (float) $milestones
            ->filter(fn (PaymentMilestone $m) => in_array($m->status, [
                PaymentMilestone::STATUS_PAID,
                PaymentMilestone::STATUS_COMPLETE,
            ], true))
            ->sum(fn (PaymentMilestone $m) => (float) ($m->paid_amount ?? $m->amount ?? 0));
    }

    private function mapFinanceApStatus(?string $workflowState): string
    {
        return match ($workflowState) {
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_INVOICE_APPROVED => 'issued',
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_PAYMENT_PROCESSED => 'partially_delivered',
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS => 'approved',
            default => 'approved',
        };
    }
}
