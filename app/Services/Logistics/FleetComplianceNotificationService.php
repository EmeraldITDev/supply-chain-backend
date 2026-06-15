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
        $users = User::query()
            ->whereIn('supply_chain_role', ['logistics_officer', 'logistics_manager'])
            ->get();

        $cc = collect(config('scm.logistics_notification_cc_emails', []));
        foreach ($cc as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }
            if ($users->contains(fn (User $u) => strcasecmp((string) $u->email, $email) === 0)) {
                continue;
            }
            $found = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
            if ($found) {
                $users->push($found);
            }
        }

        return $users->unique('id')->values();
    }

    public function notifyRecipients(object $notification): void
    {
        $recipients = $this->logisticsRecipients();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }

        $cc = collect(config('scm.logistics_notification_cc_emails', []));
        foreach ($cc as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }
            if ($recipients->contains(fn (User $u) => strcasecmp((string) $u->email, $email) === 0)) {
                continue;
            }

            Notification::route('mail', $email)->notify($notification);
        }
    }
}
