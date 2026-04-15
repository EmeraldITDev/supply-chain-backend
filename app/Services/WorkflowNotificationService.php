<?php

namespace App\Services;

use App\Mail\MRFCreatedMail;
use Illuminate\Support\Facades\Mail;
use App\Models\User;

class WorkflowNotificationService
{
    public function sendMRFCreated(array $mrf, array $roles = []): void
    {
        $recipients = $this->getEmailsByRoles($roles);

        foreach ($recipients as $email) {
            Mail::to($email)->queue(new MRFCreatedMail([
                'mrf_id' => $mrf['mrf_id'] ?? null,
                'title' => $mrf['title'] ?? null,
                'department' => $mrf['department'] ?? null,
                'created_by' => $mrf['created_by'] ?? null,
                'status' => $mrf['status'] ?? null,
                'url' => $mrf['url'] ?? null,
            ]));
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
}