<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;

class TripRequestProgressTrackerService
{
    /**
     * Staff-facing progress: Submitted → LM Review → Director Approval → Converted
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
                'description' => 'Trip request submitted by employee',
            ],
            [
                'key' => 'lm_review',
                'label' => 'Logistics Manager Review',
                'description' => 'Logistics manager reviews and forwards to Supervising Director',
            ],
            [
                'key' => 'director_approval',
                'label' => 'Supervising Director Approval',
                'description' => 'Supervising Director approves, rejects, or returns for revision',
            ],
            [
                'key' => 'converted',
                'label' => 'Logistics Request',
                'description' => 'Converted to a formal logistics request',
            ],
        ];
    }

    private function resolveCurrentStepKey(Trip $trip): string
    {
        $workflow = (string) $trip->workflow_stage;
        $metadata = is_array($trip->metadata) ? $trip->metadata : [];

        if (! empty($metadata['logistics_trip_id']) || $workflow === Trip::WORKFLOW_LOGISTICS_REVIEW) {
            return 'converted';
        }

        if (in_array($workflow, [Trip::WORKFLOW_DIRECTOR_APPROVED], true)) {
            return 'converted';
        }

        if (in_array($workflow, [Trip::WORKFLOW_DIRECTOR_REVIEW], true)) {
            return 'director_approval';
        }

        if (in_array($workflow, [Trip::WORKFLOW_TRIP_REQUEST, Trip::WORKFLOW_CHANGES_REQUESTED], true)) {
            return 'lm_review';
        }

        if (in_array($workflow, [
            Trip::WORKFLOW_PROCUREMENT_REVIEW,
            Trip::WORKFLOW_SCD_APPROVAL,
            Trip::WORKFLOW_PO_PENDING_SIGN,
            Trip::WORKFLOW_PO_SIGNED,
            Trip::WORKFLOW_COMPLETED,
        ], true)) {
            return 'converted';
        }

        return 'submitted';
    }
}
