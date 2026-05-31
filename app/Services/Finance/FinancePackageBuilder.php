<?php

namespace App\Services\Finance;

use App\Models\PaymentSchedule;
use App\Services\PaymentScheduleService;

/**
 * Stub for Phase 6 Finance AP package assembly.
 */
class FinancePackageBuilder
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPaymentScheduleSection(PaymentSchedule $schedule): array
    {
        return [
            'paymentSchedule' => $this->paymentScheduleService->toApiArray($schedule),
        ];
    }
}
