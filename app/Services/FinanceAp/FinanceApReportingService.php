<?php

namespace App\Services\FinanceAp;

use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Services\Finance\FinanceRoutingService;
use App\Services\PaymentScheduleService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;

class FinanceApReportingService
{
    public function __construct(
        private FinanceRoutingService $routing,
        private PaymentScheduleService $paymentScheduleService,
        private DeliveryConfirmationService $deliveryConfirmationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?Carbon $from, ?Carbon $to): array
    {
        $cohort = MRF::query()->tap(fn ($q) => $this->routing->scopeFinanceApCohort($q));
        $this->applyDateRange($cohort, $from, $to, 'created_at');

        $mrfIds = (clone $cohort)->pluck('id');

        $totalCases = (clone $cohort)->count();
        $packagePushed = (clone $cohort)->whereNotNull('finance_ap_case_id')->count();
        $inHandoff = (clone $cohort)->where('workflow_state', WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING)->count();
        $inReview = (clone $cohort)->whereIn('workflow_state', [
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
        ])->count();
        $closed = (clone $cohort)->whereIn('workflow_state', [
            WorkflowStateService::STATE_CLOSED,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
        ])->count();

        $rejected = FinanceSyncEvent::query()
            ->whereIn('mrf_id', $mrfIds)
            ->where('direction', FinanceSyncEvent::DIRECTION_INBOUND)
            ->where('event_type', 'rejected')
            ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        $rfi = FinanceSyncEvent::query()
            ->whereIn('mrf_id', $mrfIds)
            ->where('direction', FinanceSyncEvent::DIRECTION_INBOUND)
            ->where('event_type', 'rfi_raised')
            ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        $outstanding = $this->outstandingMilestoneBalance($mrfIds);

        return array_merge($this->reportContext(), [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'totals' => [
                'financeApMrfs' => $totalCases,
                'packagePushed' => $packagePushed,
                'financeHandoffPending' => $inHandoff,
                'inReviewOrPaying' => $inReview,
                'closedOrComplete' => $closed,
                'financeApRejections' => $rejected,
                'financeApRfiRaised' => $rfi,
                'rejectionRate' => $packagePushed > 0 ? round($rejected / $packagePushed, 4) : 0,
                'rfiRate' => $packagePushed > 0 ? round($rfi / $packagePushed, 4) : 0,
                'outstandingMilestoneBalance' => $outstanding['totalAmount'],
                'outstandingMilestoneCount' => $outstanding['count'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function outstandingMilestones(?Carbon $from, ?Carbon $to, int $limit = 50): array
    {
        $query = PaymentMilestone::query()
            ->with(['schedule.mrf'])
            ->whereNotIn('status', [PaymentMilestone::STATUS_PAID, PaymentMilestone::STATUS_COMPLETE])
            ->whereHas('schedule.mrf', function ($q) use ($from, $to) {
                $this->routing->scopeFinanceApCohort($q);
                $this->applyDateRange($q, $from, $to, 'created_at');
            })
            ->orderByDesc('amount')
            ->limit($limit);

        $rows = $query->get()->map(function (PaymentMilestone $milestone) {
            $mrf = $milestone->schedule?->mrf;

            return [
                'mrfId' => $mrf?->mrf_id,
                'formattedId' => $mrf?->formatted_id,
                'milestoneId' => $milestone->id,
                'milestoneNumber' => $milestone->milestone_number,
                'label' => $milestone->label,
                'amount' => (float) ($milestone->amount ?? 0),
                'percentage' => (float) $milestone->percentage,
                'status' => $milestone->status,
                'workflowState' => $mrf?->workflow_state,
                'financeApCaseId' => $mrf?->finance_ap_case_id,
            ];
        });

        return array_merge($this->reportContext(), [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'items' => $rows->values()->all(),
            'totalOutstanding' => (float) $rows->sum('amount'),
        ]);
    }

    /**
     * Advance paid (or in progress) but delivery documents still missing.
     *
     * @return array<string, mixed>
     */
    public function advanceDeliveryRisk(int $limit = 50): array
    {
        $items = [];

        $mrfs = MRF::query()
            ->tap(fn ($q) => $this->routing->scopeFinanceApCohort($q))
            ->whereNotNull('signed_po_url')
            ->with(['paymentSchedule.milestones'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        foreach ($mrfs as $mrf) {
            $schedule = $mrf->paymentSchedule;
            if (! $schedule || ! $this->paymentScheduleService->hasAdvanceMilestone($schedule)) {
                continue;
            }

            $evaluation = $this->deliveryConfirmationService->evaluate($mrf);
            if ($evaluation['satisfied'] || ! $this->paymentScheduleService->requiresDeliveryConfirmationStage($schedule)) {
                continue;
            }

            $advancePaid = $schedule->milestones
                ->contains(fn (PaymentMilestone $m) => $m->trigger_condition === PaymentMilestone::TRIGGER_ON_ADVANCE
                    && in_array($m->status, [PaymentMilestone::STATUS_PAID, PaymentMilestone::STATUS_COMPLETE], true));

            if (! $advancePaid && $mrf->workflow_state !== WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS) {
                continue;
            }

            $items[] = [
                'mrfId' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'workflowState' => $mrf->workflow_state,
                'missingDocuments' => $evaluation['missingDocuments'],
                'advancePaid' => $advancePaid,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return array_merge($this->reportContext(), [
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cycleTimes(?Carbon $from, ?Carbon $to): array
    {
        $baseQuery = MRF::query()
            ->tap(fn ($q) => $this->routing->scopeFinanceApCohort($q))
            ->whereNotNull('po_signed_at')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $sampleSize = (clone $baseQuery)->count();

        $poToFirstPayment = [];
        $poToClosed = [];

        (clone $baseQuery)
            ->with(['paymentSchedule.milestones'])
            ->orderBy('id')
            ->chunkById(100, function ($mrfs) use (&$poToFirstPayment, &$poToClosed) {
                foreach ($mrfs as $mrf) {
                    $firstPaid = $mrf->paymentSchedule?->milestones
                        ->filter(fn (PaymentMilestone $m) => $m->paid_at)
                        ->sortBy('paid_at')
                        ->first();

                    if ($firstPaid?->paid_at && $mrf->po_signed_at) {
                        $poToFirstPayment[] = $mrf->po_signed_at->diffInDays($firstPaid->paid_at);
                    }

                    if ($mrf->workflow_state === WorkflowStateService::STATE_CLOSED && $mrf->po_signed_at) {
                        $poToClosed[] = $mrf->po_signed_at->diffInDays($mrf->updated_at);
                    }
                }
            });

        return array_merge($this->reportContext(), [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'sampleSize' => $sampleSize,
            'avgDaysPoSignedToFirstMilestonePaid' => $poToFirstPayment !== []
                ? round(array_sum($poToFirstPayment) / count($poToFirstPayment), 1) : null,
            'avgDaysPoSignedToClosed' => $poToClosed !== []
                ? round(array_sum($poToClosed) / count($poToClosed), 1) : null,
        ]);
    }

    /**
     * @return array{cutoverDate: ?string, routingConfigured: bool}
     */
    private function reportContext(): array
    {
        return [
            'cutoverDate' => $this->routing->cutoverDate()?->toDateString(),
            'routingConfigured' => $this->routing->isRoutingConfigured(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>|\Illuminate\Database\Eloquent\Builder  $mrfIds
     * @return array{count: int, totalAmount: float}
     */
    private function outstandingMilestoneBalance($mrfIds): array
    {
        if ($mrfIds instanceof \Illuminate\Database\Eloquent\Builder) {
            $mrfIds = $mrfIds->pluck('id');
        }

        if ($mrfIds->isEmpty()) {
            return ['count' => 0, 'totalAmount' => 0.0];
        }

        $query = PaymentMilestone::query()
            ->whereHas('schedule', fn ($q) => $q->whereIn('mrf_id', $mrfIds))
            ->whereNotIn('status', [PaymentMilestone::STATUS_PAID, PaymentMilestone::STATUS_COMPLETE]);

        return [
            'count' => (int) (clone $query)->count(),
            'totalAmount' => (float) (clone $query)->sum('amount'),
        ];
    }

    private function applyDateRange($query, ?Carbon $from, ?Carbon $to, string $column): void
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }
        if ($to) {
            $query->where($column, '<=', $to);
        }
    }

    /**
     * Recent Finance AP sync events for the operations dashboard.
     *
     * @return array{summary: array<string, int>, events: list<array<string, mixed>>}
     */
    public function syncEvents(int $limit = 50, ?string $status = null, ?string $eventType = null): array
    {
        $query = FinanceSyncEvent::query()
            ->with(['mrf:id,mrf_id,formatted_id,title'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }
        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $events = $query->limit($limit)->get();

        $failed = (int) FinanceSyncEvent::query()
            ->where('status', FinanceSyncEvent::STATUS_FAILED)
            ->count();
        $pending = (int) FinanceSyncEvent::query()
            ->where('status', FinanceSyncEvent::STATUS_PENDING)
            ->count();
        $vendorSyncFailed = (int) FinanceSyncEvent::query()
            ->where('event_type', 'vendor_sync')
            ->where('status', FinanceSyncEvent::STATUS_FAILED)
            ->count();

        return [
            'summary' => [
                'failed' => $failed,
                'pending' => $pending,
                'vendorSyncFailed' => $vendorSyncFailed,
            ],
            'events' => $events->map(fn (FinanceSyncEvent $event) => [
                'id' => $event->id,
                'mrfId' => $event->mrf?->mrf_id,
                'mrfDisplayId' => $event->mrf?->formatted_id,
                'mrfTitle' => $event->mrf?->title,
                'direction' => $event->direction,
                'eventType' => $event->event_type,
                'status' => $event->status,
                'httpStatus' => $event->http_status,
                'errorMessage' => $event->error_message,
                'processedAt' => $event->processed_at?->toIso8601String(),
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
