<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\JCCLineItem;
use App\Models\Logistics\Trip;
use App\Models\Logistics\TripVendorSubmission;
use App\Notifications\JobCompletionCertificateApprovedNotification;
use Carbon\Carbon;

class JobCompletionCertificateService
{
    public function __construct(
        private JCCReferenceNumberService $referenceService,
    ) {
    }

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
            'reference_number' => $this->referenceService->generateReferenceNumber(),
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

        // Validate that there's at least one line item
        if ($jcc->lineItems()->count() === 0) {
            throw new \Exception('At least one line item is required');
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
     * Add a line item to JCC
     */
    public function addLineItem(JobCompletionCertificate $jcc, array $data): JCCLineItem
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('Cannot add line items to non-draft JCC');
        }

        $lineNumber = $jcc->lineItems()->count() + 1;

        return $jcc->lineItems()->create(array_merge([
            'line_number' => $lineNumber,
        ], $data));
    }

    /**
     * Update a line item
     */
    public function updateLineItem(JCCLineItem $item, array $data): JCCLineItem
    {
        if (!$item->jcc->isDraft()) {
            throw new \Exception('Cannot update line items in non-draft JCC');
        }

        $item->update($data);
        return $item;
    }

    /**
     * Delete a line item
     */
    public function deleteLineItem(JCCLineItem $item): void
    {
        if (!$item->jcc->isDraft()) {
            throw new \Exception('Cannot delete line items from non-draft JCC');
        }

        $item->delete();

        // Reorder remaining items
        $item->jcc->lineItems()->orderBy('line_number')->get()->each(function ($lineItem, $index) {
            $lineItem->update(['line_number' => $index + 1]);
        });
    }

    /**
     * Prefill JCC with line items from vendor submissions
     */
    public function prefillFromVendorSubmissions(JobCompletionCertificate $jcc, int $tripId): array
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('Can only prefill draft JCC');
        }

        $trip = Trip::find($tripId);
        if (!$trip) {
            throw new \Exception('Trip not found');
        }

        $submissions = $trip->vendorSubmissions()
            ->where('status', TripVendorSubmission::STATUS_APPROVED)
            ->get();

        $createdItems = [];
        foreach ($submissions as $index => $submission) {
            $lineItem = JCCLineItem::fromVendorSubmission($jcc, $submission, $index + 1);
            $createdItems[] = $lineItem;
        }

        return $createdItems;
    }

    /**
     * Add attachment to JCC
     */
    public function addAttachment(JobCompletionCertificate $jcc, string $filePath, string $fileName = null): void
    {
        $jcc->addAttachment($filePath, $fileName);
    }

    /**
     * Get JCC summary with line items
     */
    public function getJCCSummary(JobCompletionCertificate $jcc): array
    {
        return [
            'id' => $jcc->id,
            'reference_number' => $jcc->reference_number,
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
            'line_items_count' => $jcc->lineItems()->count(),
            'line_items' => $jcc->lineItems()->orderBy('line_number')->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'line_number' => $item->line_number,
                    'description' => $item->description,
                    'item_type' => $item->item_type,
                    'item_type_label' => $item->getItemTypeLabel(),
                    'condition' => $item->condition,
                    'condition_label' => $item->getConditionLabel(),
                    'remarks' => $item->remarks,
                    'reference_number' => $item->reference_number,
                ];
            }),
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
        $query = JobCompletionCertificate::query()->with(['trip', 'issuedBy', 'approvedBy', 'lineItems']);

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
