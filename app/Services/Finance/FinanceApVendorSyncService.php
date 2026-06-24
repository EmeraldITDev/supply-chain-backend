<?php

namespace App\Services\Finance;

use App\Models\FinanceSyncEvent;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes SCM vendor snapshots to Finance AP as they are created or updated,
 * so Finance AP does not depend on an MRF package push to populate vendors.
 */
class FinanceApVendorSyncService
{
    public function __construct(
        private FinanceApVendorSnapshotBuilder $snapshotBuilder,
        private FinanceIntegrationService $financeIntegration,
    ) {
    }

    public function isEnabled(): bool
    {
        return config('finance_ap.vendor_sync_enabled', true)
            && $this->financeIntegration->isConfigured();
    }

    public function shouldSync(Vendor $vendor): bool
    {
        if ($vendor->status === 'Inactive') {
            return false;
        }

        if (trim((string) $vendor->name) === '') {
            return false;
        }

        return true;
    }

    public function pushVendor(Vendor $vendor, bool $forceResync = false): bool
    {
        if (! $this->isEnabled() || ! $this->shouldSync($vendor)) {
            return false;
        }

        $snapshot = $this->snapshotBuilder->toArray($vendor);
        $payload = ['vendor' => $snapshot];
        $payloadHash = hash('sha256', json_encode($payload));
        $idempotencyKey = $this->buildIdempotencyKey($vendor, $forceResync);

        if (! $forceResync) {
            $alreadySynced = FinanceSyncEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
                ->where('payload_hash', $payloadHash)
                ->exists();

            if ($alreadySynced) {
                return true;
            }
        }

        $event = $this->logOutboundEvent($vendor, $idempotencyKey, $payload, $payloadHash);

        try {
            $response = Http::baseUrl(config('finance_ap.base_url'))
                ->timeout(config('finance_ap.http_timeout', 30))
                ->withHeaders([
                    'X-Api-Key' => config('finance_ap.api_key'),
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->post(config('finance_ap.paths.vendor_sync'), $payload);

            $body = $response->json();
            $event->update([
                'http_status' => $response->status(),
                'response_payload' => is_array($body) ? $body : ['raw' => $response->body()],
                'status' => $response->successful() ? FinanceSyncEvent::STATUS_SUCCESS : FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $response->successful() ? null : ($body['message'] ?? $body['error'] ?? $response->body()),
                'processed_at' => now(),
            ]);

            if (! $response->successful()) {
                Log::warning('Finance AP vendor sync failed', [
                    'vendor_id' => $vendor->vendor_id,
                    'scm_vendor_id' => $vendor->id,
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return false;
            }

            Log::info('Finance AP vendor synced', [
                'vendor_id' => $vendor->vendor_id,
                'scm_vendor_id' => $vendor->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            $event->update([
                'status' => FinanceSyncEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('Finance AP vendor sync exception', [
                'vendor_id' => $vendor->vendor_id,
                'scm_vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{synced: int, skipped: int, failed: int}
     */
    public function pushAllActiveVendors(bool $dryRun = true, bool $forceResync = false): array
    {
        $stats = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

        Vendor::query()
            ->where('status', '!=', 'Inactive')
            ->orderBy('id')
            ->chunkById(50, function (Collection $vendors) use ($dryRun, $forceResync, &$stats) {
                foreach ($vendors as $vendor) {
                    if (! $this->shouldSync($vendor)) {
                        $stats['skipped']++;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['synced']++;

                        continue;
                    }

                    if ($this->pushVendor($vendor, $forceResync)) {
                        $stats['synced']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            });

        return $stats;
    }

    private function buildIdempotencyKey(Vendor $vendor, bool $forceResync): string
    {
        if ($forceResync) {
            return sprintf('vendor:%d:resync:%s', $vendor->id, uniqid('', true));
        }

        return sprintf(
            'vendor:%d:v%s',
            $vendor->id,
            $vendor->updated_at?->timestamp ?? now()->timestamp
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logOutboundEvent(
        Vendor $vendor,
        string $idempotencyKey,
        array $payload,
        string $payloadHash,
    ): FinanceSyncEvent {
        return FinanceSyncEvent::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'mrf_id' => null,
                'scm_transaction_id' => null,
                'direction' => FinanceSyncEvent::DIRECTION_OUTBOUND,
                'event_type' => 'vendor_sync',
                'correlation_id' => 'vendor:'.$vendor->id,
                'payload_hash' => $payloadHash,
                'request_payload' => $payload,
                'response_payload' => null,
                'http_status' => null,
                'error_message' => null,
                'processed_at' => null,
                'status' => FinanceSyncEvent::STATUS_PENDING,
            ]
        );
    }
}
