<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;

class TripRequestProgressTrackerService
{
    /**
     * Staff-facing progress: Submitted → Logistics Review → Confirmed → Completed
     *
     * @return array<string, mixed>
     */
    public function build(Trip $trip): array
    {
        $steps = $this->staffSteps();
        $currentKey = $this->resolveCurrentStepKey($trip);
        $currentIndex = collect($steps)->search(fn (array $s) => $s['key'] === $currentKey);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $mapped = [];
        foreach ($steps as $index => $step) {
            if ($index < $currentIndex) {
                $status = 'completed';
            } elseif ($index === $currentIndex) {
                $status = strtolower((string) $trip->status) === Trip::STATUS_CANCELLED ? 'cancelled' : 'in_progress';
            } else {
                $status = 'pending';
            }

            $mapped[] = array_merge($step, [
                'status' => $status,
                'step' => $index + 1,
            ]);
        }

        $completedCount = collect($mapped)->where('status', 'completed')->count();

        return [
            'tripId' => $trip->id,
            'tripCode' => $trip->trip_code,
            'workflowStage' => $trip->workflow_stage,
            'operationalStatus' => $trip->status,
            'currentStepKey' => $currentKey,
            'currentStep' => $currentIndex + 1,
            'totalSteps' => count($mapped),
            'completedSteps' => $completedCount,
            'progressPercent' => count($mapped) > 0
                ? (int) round(($completedCount / count($mapped)) * 100)
                : 0,
            'steps' => $mapped,
        ];
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    private function staffSteps(): array
    {
        return [
            [
                'key' => 'submitted',
                'label' => 'Submitted',
                'description' => 'Trip request submitted and awaiting logistics review',
            ],
            [
                'key' => 'logistics_review',
                'label' => 'Logistics Review',
                'description' => 'Logistics is reviewing the request and coordinating procurement',
            ],
            [
                'key' => 'confirmed',
                'label' => 'Confirmed',
                'description' => 'Vendor and approvals confirmed; PO workflow in progress',
            ],
            [
                'key' => 'completed',
                'label' => 'Completed',
                'description' => 'Trip request completed or closed',
            ],
        ];
    }

    private function resolveCurrentStepKey(Trip $trip): string
    {
        $workflow = (string) $trip->workflow_stage;
        $status = strtolower((string) $trip->status);

        if (in_array($workflow, [Trip::WORKFLOW_PO_SIGNED, Trip::WORKFLOW_COMPLETED], true)
            || in_array($status, [Trip::STATUS_CLOSED, Trip::STATUS_COMPLETED], true)) {
            return 'completed';
        }

        if (in_array($workflow, [
            Trip::WORKFLOW_SCD_APPROVAL,
            Trip::WORKFLOW_PO_PENDING_SIGN,
        ], true) || filled($trip->selected_vendor_id)) {
            return 'confirmed';
        }

        if (in_array($workflow, [
            Trip::WORKFLOW_PROCUREMENT_REVIEW,
            Trip::WORKFLOW_LOGISTICS_REVIEW,
        ], true)) {
            return 'logistics_review';
        }

        return 'submitted';
    }
}
