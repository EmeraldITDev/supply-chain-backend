<?php

namespace App\Console\Commands;

use App\Models\Logistics\Document;
use App\Models\Logistics\Vehicle;
use App\Notifications\FleetDocumentExpiryNotification;
use App\Services\Logistics\FleetComplianceNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckFleetVehicleDocuments extends Command
{
    protected $signature = 'fleet:check-vehicle-documents';

    protected $description = 'Deactivate vehicles when fleet documents expire and send tiered reminders (6-week window)';

    public function handle(FleetComplianceNotificationService $notifySvc): int
    {
        $today = Carbon::today();
        $week = $notifySvc->weekPeriodKey();

        foreach (
            Document::query()
                ->with('documentable')
                ->where('documentable_type', Vehicle::class)
                ->where('is_active', true)
                ->whereNotNull('expires_at')
                ->cursor() as $doc
        ) {
            $vehicle = $doc->documentable;
            if (!$vehicle instanceof Vehicle) {
                continue;
            }

            $expiry = Carbon::parse($doc->expires_at)->startOfDay();
            $daysRemaining = (int) $today->diffInDays($expiry, false);

            if ($daysRemaining <= 0) {
                if ($vehicle->status !== Vehicle::STATUS_INACTIVE) {
                    $vehicle->update([
                        'status' => Vehicle::STATUS_INACTIVE,
                        'status_inactive_reason' => Vehicle::INACTIVE_REASON_DOCUMENT_EXPIRED,
                    ]);
                }

                if ($notifySvc->shouldDispatch($doc, 'doc_expired_inactive', $week)) {
                    $msg = "Document expired: {$doc->document_type} for {$vehicle->plate_number}. Vehicle set to Inactive.";
                    $notifySvc->notifyRecipients(
                        new FleetDocumentExpiryNotification($doc, $daysRemaining, 'RED', $msg)
                    );
                }

                continue;
            }

            if ($daysRemaining > 42) {
                continue;
            }

            $colour = 'AMBER';
            if ($daysRemaining >= 1 && $daysRemaining <= 7) {
                $colour = 'RED';
            }

            $channel = $colour === 'RED' ? 'doc_expiry_red' : 'doc_expiry_amber';
            if (!$notifySvc->shouldDispatch($doc, $channel, $week)) {
                continue;
            }

            $notifySvc->notifyRecipients(
                new FleetDocumentExpiryNotification($doc, $daysRemaining, $colour, null)
            );
        }

        $this->info('Fleet vehicle document check complete.');

        return self::SUCCESS;
    }
}
