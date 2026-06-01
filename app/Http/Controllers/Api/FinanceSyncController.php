<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Http\Request;

class FinanceSyncController extends Controller
{
    public function __construct(
        private FinanceIntegrationService $financeIntegration,
    ) {
    }

    public function show(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $events = FinanceSyncEvent::query()
            ->where('mrf_id', $mrf->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (FinanceSyncEvent $event) => [
                'id' => $event->id,
                'direction' => $event->direction,
                'eventType' => $event->event_type,
                'status' => $event->status,
                'httpStatus' => $event->http_status,
                'correlationId' => $event->correlation_id,
                'errorMessage' => $event->error_message,
                'processedAt' => $event->processed_at?->toIso8601String(),
                'createdAt' => $event->created_at?->toIso8601String(),
            ]);

        $lastOutbound = $events->first(fn ($e) => $e['direction'] === FinanceSyncEvent::DIRECTION_OUTBOUND);
        $lastInbound = $events->first(fn ($e) => $e['direction'] === FinanceSyncEvent::DIRECTION_INBOUND);

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'usesFinanceAp' => mrfUsesFinanceAp($mrf),
                'financeApCaseId' => $mrf->finance_ap_case_id,
                'financeApStatus' => $mrf->finance_ap_status,
                'workflowState' => $mrf->workflow_state,
                'packagePushed' => $this->financeIntegration->hasPackageBeenPushed($mrf),
                'integrationConfigured' => $this->financeIntegration->isConfigured(),
                'financeApBaseUrl' => config('finance_ap.base_url'),
                'lastOutbound' => $lastOutbound,
                'lastInbound' => $lastInbound,
                'recentEvents' => $events->values(),
            ],
        ]);
    }

    private function findMrf(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id);

                if (is_numeric($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->first();
    }
}
