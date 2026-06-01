<?php

namespace App\Services\Finance;

use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Notifications\SystemAnnouncementNotification;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\FinanceAp\FinanceApWorkflowOrchestrator;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FinanceIntegrationService
{
    public function __construct(
        private FinancePackageBuilder $packageBuilder,
        private WorkflowStateService $workflowStateService,
        private ClosureReadinessService $closureReadinessService,
    ) {
    }

    public function isConfigured(): bool
    {
        return config('finance_ap.enabled')
            && filled(config('finance_ap.base_url'))
            && filled(config('finance_ap.api_key'));
    }

    public function hasPackageBeenPushed(MRF $mrf): bool
    {
        if (! Schema::hasColumn($mrf->getTable(), 'finance_ap_case_id')) {
            return false;
        }

        return filled($mrf->finance_ap_case_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPackage(MRF $mrf, ?int $requestedMilestoneId = null, ?string $deltaReason = null): array
    {
        return $this->packageBuilder->build($mrf, $requestedMilestoneId, $deltaReason);
    }

    public function pushPackage(MRF $mrf, ?int $requestedMilestoneId = null, ?User $actor = null): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        if (! $this->isConfigured()) {
            Log::warning('Finance AP push skipped; integration not configured', [
                'mrf_id' => $mrf->mrf_id,
            ]);

            return false;
        }

        $package = $this->buildPackage($mrf, $requestedMilestoneId);
        $idempotencyKey = sprintf(
            '%s:package_push:v%d',
            $mrf->scm_transaction_id,
            $package['packageVersion'] ?? 1
        );

        $event = $this->logOutboundEvent($mrf, 'package_push', $idempotencyKey, $package);

        try {
            $response = Http::baseUrl(config('finance_ap.base_url'))
                ->timeout(config('finance_ap.http_timeout', 30))
                ->withHeaders([
                    'X-Api-Key' => config('finance_ap.api_key'),
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post(config('finance_ap.paths.package'), $package);

            $body = $response->json();
            $event->update([
                'http_status' => $response->status(),
                'response_payload' => is_array($body) ? $body : ['raw' => $response->body()],
                'status' => $response->successful() ? FinanceSyncEvent::STATUS_SUCCESS : FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $response->successful() ? null : ($body['message'] ?? $response->body()),
                'processed_at' => now(),
            ]);

            if (! $response->successful()) {
                Log::error('Finance AP package push failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return false;
            }

            $data = $body['data'] ?? $body;
            $caseId = $data['financeApCaseId'] ?? $data['finance_ap_case_id'] ?? null;
            $caseStatus = $data['status'] ?? $data['finance_ap_status'] ?? 'pending_review';

            if ($caseId) {
                $mrf->update([
                    'finance_ap_case_id' => (string) $caseId,
                    'finance_ap_status' => (string) $caseStatus,
                ]);
            }

            $this->transitionAfterSuccessfulPush($mrf, $actor);

            return true;
        } catch (\Throwable $e) {
            $event->update([
                'status' => FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('Finance AP package push exception', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function pushDelta(MRF $mrf, string $reason, ?User $actor = null): bool
    {
        if (! $this->hasPackageBeenPushed($mrf)) {
            Log::info('Finance AP delta push skipped; package not yet pushed', [
                'mrf_id' => $mrf->mrf_id,
                'reason' => $reason,
            ]);

            return false;
        }

        if (! $this->isConfigured()) {
            return false;
        }

        $package = $this->buildPackage($mrf, null, $reason);
        $idempotencyKey = sprintf(
            '%s:delta:%s:v%d',
            $mrf->scm_transaction_id,
            $reason,
            $package['packageVersion'] ?? 1
        );

        $path = str_replace(
            '{scm_transaction_id}',
            $mrf->scm_transaction_id,
            config('finance_ap.paths.delta')
        );

        $event = $this->logOutboundEvent($mrf, 'package_delta', $idempotencyKey, $package);

        try {
            $response = Http::baseUrl(config('finance_ap.base_url'))
                ->timeout(config('finance_ap.http_timeout', 30))
                ->withHeaders([
                    'X-Api-Key' => config('finance_ap.api_key'),
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post($path, [
                    'reason' => $reason,
                    'package' => $package,
                ]);

            $body = $response->json();
            $event->update([
                'http_status' => $response->status(),
                'response_payload' => is_array($body) ? $body : ['raw' => $response->body()],
                'status' => $response->successful() ? FinanceSyncEvent::STATUS_SUCCESS : FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $response->successful() ? null : ($body['message'] ?? $response->body()),
                'processed_at' => now(),
            ]);

            if ($response->successful() && isset($body['data']['status'])) {
                $mrf->update(['finance_ap_status' => $body['data']['status']]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            $event->update([
                'status' => FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, ?string $signature, ?string $rawBody = null): array
    {
        if (! $this->verifyWebhookSignature($rawBody ?? json_encode($payload), $signature)) {
            return ['success' => false, 'error' => 'Invalid webhook signature', 'code' => 'INVALID_SIGNATURE'];
        }

        $eventId = $payload['event_id'] ?? $payload['eventId'] ?? null;
        $eventType = $payload['event_type'] ?? $payload['eventType'] ?? null;
        $scmTransactionId = $payload['scm_transaction_id'] ?? $payload['scmTransactionId'] ?? null;

        if (! $eventType || ! $scmTransactionId) {
            return ['success' => false, 'error' => 'Missing event_type or scm_transaction_id', 'code' => 'VALIDATION_ERROR'];
        }

        if ($eventId && FinanceSyncEvent::query()->where('idempotency_key', 'inbound:' . $eventId)->exists()) {
            return ['success' => true, 'duplicate' => true, 'message' => 'Event already processed'];
        }

        $mrf = MRF::query()->where('scm_transaction_id', $scmTransactionId)->first();

        if (! $mrf) {
            return ['success' => false, 'error' => 'MRF not found for scm_transaction_id', 'code' => 'NOT_FOUND'];
        }

        $inbound = $this->logInboundEvent($mrf, $eventType, $eventId ? 'inbound:' . $eventId : null, $payload);

        try {
            $result = match ($eventType) {
                'approved' => $this->handleApproved($mrf, $payload),
                'rejected' => $this->handleRejected($mrf, $payload),
                'payment_posted' => $this->handlePaymentPosted($mrf, $payload),
                'case_closed' => $this->handleCaseClosed($mrf, $payload),
                'rfi_raised' => $this->handleRfiRaised($mrf, $payload),
                default => ['handled' => false, 'message' => 'Unknown event_type'],
            };

            $inbound->update([
                'status' => FinanceSyncEvent::STATUS_SUCCESS,
                'processed_at' => now(),
                'response_payload' => $result,
            ]);

            if (isset($payload['finance_ap_status']) || isset($payload['financeApStatus'])) {
                $mrf->update([
                    'finance_ap_status' => $payload['finance_ap_status'] ?? $payload['financeApStatus'],
                ]);
            }

            return ['success' => true, 'data' => $result];
        } catch (\Throwable $e) {
            $inbound->update([
                'status' => FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<FinanceSyncEvent>
     */
    public function recentEventsForMrf(MRF $mrf, int $limit = 20): array
    {
        return FinanceSyncEvent::query()
            ->where('mrf_id', $mrf->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    private function transitionAfterSuccessfulPush(MRF $mrf, ?User $actor): void
    {
        $mrf->refresh();
        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($state !== WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING) {
            return;
        }

        $user = $actor ?? $this->systemActor();

        if ($this->workflowStateService->canTransition($state, WorkflowStateService::STATE_FINANCE_IN_REVIEW)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_FINANCE_IN_REVIEW, $user);
            $mrf->update(['finance_ap_status' => 'in_review']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleApproved(MRF $mrf, array $payload): array
    {
        $user = $this->systemActor();
        $milestoneId = $payload['scm_milestone_id'] ?? $payload['scmMilestoneId'] ?? null;

        if ($milestoneId) {
            $this->updateMilestoneFromWebhook($mrf, (string) $milestoneId, [
                'status' => PaymentMilestone::STATUS_PAYMENT_REQUESTED,
            ]);
        }

        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($this->workflowStateService->canTransition($state, WorkflowStateService::STATE_FINANCE_IN_REVIEW)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_FINANCE_IN_REVIEW, $user);
        } elseif ($this->workflowStateService->canTransition($state, WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS, $user);
        }

        return ['workflowState' => $mrf->fresh()->workflow_state];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleRejected(MRF $mrf, array $payload): array
    {
        $reason = $payload['reason'] ?? $payload['message'] ?? 'Rejected by Finance AP';

        $this->notifyProcurementRoles(
            'Finance AP rejected case',
            "MRF {$mrf->mrf_id} was rejected by Finance AP: {$reason}",
            "/mrfs/{$mrf->mrf_id}",
            'high'
        );

        $mrf->update(['finance_ap_status' => 'rejected']);

        return ['notified' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handlePaymentPosted(MRF $mrf, array $payload): array
    {
        $milestoneId = $payload['scm_milestone_id'] ?? $payload['scmMilestoneId'] ?? null;
        $paidAmount = (float) ($payload['paid_amount'] ?? $payload['paidAmount'] ?? 0);
        $reference = $payload['finance_ap_reference'] ?? $payload['financeApReference'] ?? null;
        $paidAt = $payload['paid_at'] ?? $payload['paidAt'] ?? now()->toIso8601String();

        if ($milestoneId) {
            $this->updateMilestoneFromWebhook($mrf, (string) $milestoneId, [
                'status' => PaymentMilestone::STATUS_PAID,
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt,
                'finance_ap_reference' => $reference,
            ]);
        }

        $user = $this->systemActor();
        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($this->workflowStateService->canTransition($state, WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS, $user);
            $mrf->refresh();
            $state = $mrf->workflow_state;
        }

        app(FinanceApWorkflowOrchestrator::class)->syncIntermediateCompletionStates($mrf, $user);

        return ['workflowState' => $mrf->fresh()->workflow_state];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleCaseClosed(MRF $mrf, array $payload): array
    {
        $user = $this->systemActor();
        $mrf->update(['finance_ap_status' => 'closed']);

        $readiness = $this->closureReadinessService->evaluate($mrf);

        if ($readiness['can_close']) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_CLOSED, $user);
        }

        return ['canClose' => $readiness['can_close'], 'blockers' => $readiness['blockers']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handleRfiRaised(MRF $mrf, array $payload): array
    {
        $details = $payload['details'] ?? $payload['message'] ?? 'Finance AP requested information';

        $this->notifyProcurementRoles(
            'Finance AP information request',
            "MRF {$mrf->mrf_id}: {$details}",
            "/mrfs/{$mrf->mrf_id}",
            'normal'
        );

        $mrf->update(['finance_ap_status' => 'rfi']);

        return ['notified' => true];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateMilestoneFromWebhook(MRF $mrf, string $scmMilestoneId, array $attributes): void
    {
        $schedule = app(\App\Services\PaymentScheduleService::class)->findForMrf($mrf);

        if (! $schedule) {
            return;
        }

        $milestone = $schedule->milestones()->where('id', $scmMilestoneId)->first();

        if (! $milestone) {
            return;
        }

        $update = [];

        if (isset($attributes['status'])) {
            $update['status'] = $attributes['status'];
        }

        if (isset($attributes['paid_amount'])) {
            $update['paid_amount'] = $attributes['paid_amount'];
        }

        if (isset($attributes['paid_at'])) {
            $update['paid_at'] = $attributes['paid_at'];
        }

        if (isset($attributes['finance_ap_reference'])) {
            $update['finance_ap_reference'] = $attributes['finance_ap_reference'];
        }

        if ($update !== []) {
            $milestone->update($update);
        }
    }

    private function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('finance_ap.webhook_secret');

        if (! $secret) {
            Log::warning('Finance AP webhook secret not configured; rejecting webhook');

            return false;
        }

        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Refresh signed URL and manifest fields for Finance AP document pull.
     *
     * @return array<string, mixed>|null
     */
    public function refreshDocument(MRF $mrf, int $documentId): ?array
    {
        $document = ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('id', $documentId)
            ->where('is_active', true)
            ->first();

        if (! $document || ! $document->file_path) {
            return null;
        }

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $freshUrl = app(ProcurementDocumentService::class)->refreshFileUrl($document->file_path, $disk);

        $document->update(['file_url' => $freshUrl]);

        return [
            'documentId' => (string) $document->id,
            'scmTransactionId' => $mrf->scm_transaction_id,
            'type' => $document->type,
            'fileName' => $document->file_name,
            'fileUrl' => $freshUrl,
            'version' => (int) $document->version,
            'uploadedAt' => $document->uploaded_at?->toIso8601String(),
            'refreshedAt' => now()->toIso8601String(),
        ];
    }

    private function systemActor(): User
    {
        return User::query()
            ->whereIn('role', ['admin', 'finance', 'finance_officer'])
            ->orderBy('id')
            ->first() ?? new User(['id' => 0, 'role' => 'admin', 'name' => 'System']);
    }

    private function notifyProcurementRoles(string $title, string $message, string $url, string $priority = 'normal'): void
    {
        try {
            $users = User::query()
                ->whereIn('role', ['procurement_manager', 'procurement', 'admin'])
                ->get();

            foreach ($users as $user) {
                $user->notify(new SystemAnnouncementNotification($title, $message, $url, $priority));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify procurement of Finance AP event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logOutboundEvent(MRF $mrf, string $eventType, string $idempotencyKey, array $payload): FinanceSyncEvent
    {
        return FinanceSyncEvent::create([
            'mrf_id' => $mrf->id,
            'scm_transaction_id' => $mrf->scm_transaction_id,
            'direction' => FinanceSyncEvent::DIRECTION_OUTBOUND,
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', json_encode($payload)),
            'request_payload' => $payload,
            'status' => FinanceSyncEvent::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logInboundEvent(MRF $mrf, string $eventType, ?string $idempotencyKey, array $payload): FinanceSyncEvent
    {
        return FinanceSyncEvent::create([
            'mrf_id' => $mrf->id,
            'scm_transaction_id' => $mrf->scm_transaction_id,
            'direction' => FinanceSyncEvent::DIRECTION_INBOUND,
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $payload['event_id'] ?? $payload['eventId'] ?? (string) Str::uuid(),
            'payload_hash' => hash('sha256', json_encode($payload)),
            'request_payload' => $payload,
            'status' => FinanceSyncEvent::STATUS_PENDING,
        ]);
    }
}
