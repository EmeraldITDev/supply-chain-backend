<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\Trip;
use App\Notifications\JobCompletionCertificateApprovedNotification;
use Carbon\Carbon;

class JobCompletionCertificateService
{
    /**
     * Create a new JCC for a trip
     */
    public function createJCC(Trip $trip, int $issuedBy, array $data = []): JobCompletionCertificate
    {
        // Check if JCC already exists
        if ($trip->jobCompletionCertificate) {
            return $trip->jobCompletionCertificate;
        }

        $jcc = JobCompletionCertificate::create(array_merge([
            'trip_id' => $trip->id,
            'issued_by' => $issuedBy,
            'status' => JobCompletionCertificate::STATUS_DRAFT,
        ], $data));

        return $jcc;
    }

    /**
     * Update JCC details (while in DRAFT status)
     */
    public function updateJCC(JobCompletionCertificate $jcc, array $data): JobCompletionCertificate
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('Cannot update JCC that is not in draft status');
        }

        $jcc->update($data);

        return $jcc;
    }

    /**
     * Submit the JCC for approval
     */
    public function submitJCC(JobCompletionCertificate $jcc): JobCompletionCertificate
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('JCC must be in draft status to submit');
        }

        // Validate required fields
        if (!$jcc->delivery_confirmed && !$jcc->remarks) {
            throw new \Exception('Either delivery must be confirmed or remarks must be provided');
        }

        $jcc->submit();

        return $jcc;
    }

    /**
     * Approve the JCC and close the trip
     */
    public function approveJCC(JobCompletionCertificate $jcc, int $approvedBy, string $remarks = null): JobCompletionCertificate
    {
        if (!$jcc->isSubmitted()) {
            throw new \Exception('JCC must be submitted to be approved');
        }

        $jcc->approve($approvedBy, $remarks);

        // Notify relevant stakeholders
        $trip = $jcc->trip;
        if ($trip->vendor && $trip->vendor->users()->first()) {
            $trip->vendor->users()->first()->notify(
                new JobCompletionCertificateApprovedNotification($trip, $jcc)
            );
        }

        // Notify trip creator
        if ($trip->creator) {
            $trip->creator->notify(new JobCompletionCertificateApprovedNotification($trip, $jcc));
        }

        return $jcc;
    }

    /**
     * Get JCC for a trip
     */
    public function getJCCForTrip(Trip $trip): ?JobCompletionCertificate
    {
        return $trip->jobCompletionCertificate;
    }

    /**
     * Add attachment to JCC
     */
    public function addAttachment(JobCompletionCertificate $jcc, string $filePath, string $fileName = null): void
    {
        $jcc->addAttachment($filePath, $fileName);
    }

    /**
     * Get JCC summary
     */
    public function getJCCSummary(JobCompletionCertificate $jcc): array
    {
        return [
            'id' => $jcc->id,
            'trip_id' => $jcc->trip_id,
            'trip_code' => $jcc->trip->trip_code,
            'trip_title' => $jcc->trip->title,
            'status' => $jcc->status,
            'issued_by' => $jcc->issuedBy?->name,
            'issued_at' => $jcc->issued_at?->format('Y-m-d H:i'),
            'approved_by' => $jcc->approvedBy?->name,
            'approved_at' => $jcc->approved_at?->format('Y-m-d H:i'),
            'delivery_confirmed' => $jcc->delivery_confirmed,
            'remarks' => $jcc->remarks,
            'condition_of_goods' => $jcc->condition_of_goods,
            'approval_remarks' => $jcc->approval_remarks,
            'attachments_count' => count($jcc->attachments ?? []),
            'created_at' => $jcc->created_at->format('Y-m-d H:i'),
            'updated_at' => $jcc->updated_at->format('Y-m-d H:i'),
        ];
    }

    /**
     * Check if trip can be closed (JCC must be approved)
     */
    public function canClosedTrip(Trip $trip): bool
    {
        $jcc = $trip->jobCompletionCertificate;
        return $jcc && $jcc->isApproved();
    }

    /**
     * Get all JCCs with filters
     */
    public function getAllJCCs(
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 15
    ) {
        $query = JobCompletionCertificate::query()->with(['trip', 'issuedBy', 'approvedBy']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query->paginate($perPage);
    }
}
