<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\User;
use App\Services\ManualVendorOnboardingService;
use App\Services\NotificationService;
use App\Services\WorkflowNotificationService;
use App\Support\DatabaseNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPurchaseOrderNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $mrfPrimaryKey,
        public int $userId,
        public string $poNumber,
        public bool $fastTrack,
        public bool $isRegeneration,
        public bool $hasRfq,
    ) {
    }

    public function handle(
        NotificationService $notificationService,
        WorkflowNotificationService $workflowNotificationService,
        ManualVendorOnboardingService $manualVendorOnboarding,
    ): void {
        $mrf = MRF::query()->find($this->mrfPrimaryKey);
        $user = User::query()->find($this->userId);

        if (! $mrf || ! $user) {
            return;
        }

        $action = $this->isRegeneration ? 'approved' : 'generated_po';
        $remarks = $this->isRegeneration
            ? "PO regenerated after rejection: {$this->poNumber}"
            : "PO generated: {$this->poNumber}";
        if ($this->fastTrack) {
            $remarks .= ' (fast-tracked from Procurement Overview, executive review bypassed)';
        }
        if (! $this->hasRfq) {
            $remarks .= ' (synthetic PO dataset — no RFQ on record)';
        }

        MRFApprovalHistory::record($mrf, $action, 'procurement', $user, $remarks);

        try {
            Activity::create([
                'type' => 'po_generated',
                'title' => 'PO Generated',
                'description' => "Purchase Order {$this->poNumber} was generated for MRF {$mrf->mrf_id}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'mrf',
                'entity_id' => $mrf->mrf_id,
                'status' => 'finance',
            ]);
        } catch (\Throwable) {
            // Non-blocking
        }

        try {
            $financeTeam = User::query()->whereIn('supply_chain_role', ['finance', 'admin'])->get();

            foreach ($financeTeam as $finance) {
                DatabaseNotifications::send($finance, new \App\Notifications\SystemAnnouncementNotification(
                    'PO Generated',
                    "Purchase Order {$this->poNumber} for MRF {$mrf->mrf_id} has been generated and is ready for review.",
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send PO generation notification to Finance team', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        $notificationService->notifyPOReadyForSignature($mrf->fresh());

        if (! $this->fastTrack) {
            try {
                $mrf->loadMissing(['requester', 'selectedVendor']);
                $workflowNotificationService->notifyPOGenerated($mrf);
            } catch (\Throwable $e) {
                Log::error('Failed to queue PO generated emails', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $manualVendorOnboarding->finalizeVendorsForPoGeneration($mrf->fresh(), $this->isRegeneration);
    }
}
