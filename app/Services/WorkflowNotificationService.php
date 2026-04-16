<?php

namespace App\Services;

use App\Mail\MRFCreatedMail;
use App\Mail\SRFCreatedMail;
use App\Mail\MRFApprovedMail;
use App\Mail\MRFRejectedMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WorkflowNotificationService
{
    public function notifyMRFSubmitted($mrf): void
    {
        $emails = $this->getEmailsByRoles(
            $this->isEmeraldContract($mrf)
                ? ['executive']
                : ['supply_chain_director']
        );

        foreach ($emails as $email) {
            Mail::to($email)->queue(new MRFCreatedMail($mrf));
        }
    }

    public function notifySRFSubmitted($srf): void
    {
        $emails = $this->getEmailsByRoles(['supply_chain_director', 'procurement_manager', 'executive']);

        foreach ($emails as $email) {
            Mail::to($email)->queue(new SRFCreatedMail($srf));
        }
    }

    public function notifyMRFApproved($mrf): void
    {
        $emails = collect([
            $mrf->requester?->email ?? null,
        ])
        ->filter()
        ->unique()
        ->values()
        ->toArray();

        foreach ($emails as $email) {
            Mail::to($email)->queue(new MRFApprovedMail($mrf));
        }
    }

    public function notifyMRFRejected($mrf, ?string $remarks = null): void
    {
        $emails = collect([
            $mrf->requester?->email ?? null,
        ])
        ->filter()
        ->unique()
        ->values()
        ->toArray();

        foreach ($emails as $email) {
            Mail::to($email)->queue(new MRFRejectedMail($mrf, $remarks));
        }
    }

    private function getEmailsByRoles(array $roles): array
    {
        return User::whereIn('role', $roles)
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->toArray();
    }

    private function isEmeraldContract($mrf): bool
    {
        return strtolower(trim((string) $mrf->contract_type)) === 'emerald';
    }
}