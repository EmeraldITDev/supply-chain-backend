<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;

class TripService
{
    private array $allowedTransitions = [
        Trip::STATUS_DRAFT => [Trip::STATUS_SCHEDULED],
        Trip::STATUS_SCHEDULED => [Trip::STATUS_VENDOR_ASSIGNED, Trip::STATUS_DRAFT],
        Trip::STATUS_VENDOR_ASSIGNED => [Trip::STATUS_IN_PROGRESS, Trip::STATUS_SCHEDULED],
        Trip::STATUS_IN_PROGRESS => [Trip::STATUS_COMPLETED],
        Trip::STATUS_COMPLETED => [Trip::STATUS_CLOSED],
        Trip::STATUS_CLOSED => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->allowedTransitions[$from] ?? [], true);
    }
}
