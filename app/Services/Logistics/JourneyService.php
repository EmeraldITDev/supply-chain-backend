<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Journey;

class JourneyService
{
    private array $allowedTransitions = [
        Journey::STATUS_NOT_STARTED => [Journey::STATUS_DEPARTED],
        Journey::STATUS_DEPARTED => [Journey::STATUS_EN_ROUTE, Journey::STATUS_ARRIVED],
        Journey::STATUS_EN_ROUTE => [Journey::STATUS_ARRIVED],
        Journey::STATUS_ARRIVED => [Journey::STATUS_CLOSED],
        Journey::STATUS_CLOSED => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->allowedTransitions[$from] ?? [], true);
    }
}
