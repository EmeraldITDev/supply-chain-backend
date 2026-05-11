<?php

namespace App\Services\Logistics;

use App\Models\Logistics\FleetNotificationDispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class FleetComplianceNotificationService
{
    public function weekPeriodKey(?Carbon $at = null): string
    {
        $at = $at ?? Carbon::now();

        return sprintf('%d-W%02d', $at->isoWeekYear(), $at->isoWeek());
    }

    public function shouldDispatch(Model $subject, string $channel, string $periodKey): bool
    {
        $exists = FleetNotificationDispatch::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('channel', $channel)
            ->where('period_key', $periodKey)
            ->exists();

        if ($exists) {
            return false;
        }

        FleetNotificationDispatch::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'channel' => $channel,
            'period_key' => $periodKey,
        ]);

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    public function logisticsRecipients(): Collection
    {
        return User::query()
            ->whereIn('role', ['logistics_officer', 'logistics_manager'])
            ->get();
    }

    public function notifyRecipients(object $notification): void
    {
        $recipients = $this->logisticsRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }
}
