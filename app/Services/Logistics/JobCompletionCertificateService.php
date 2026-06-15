<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\JCCLineItem;
use App\Models\Logistics\Trip;
use App\Models\Logistics\TripVendorSubmission;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\JobCompletionCertificateApprovedNotification;
use App\Support\DocumentDisplayPayload;
use App\Support\SignatureUrls;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

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

        $lineItemsInput = $data['line_items'] ?? [];
        unset($data['line_items']);

        if (isset($data['certification_statement']) && ! isset($data['certification_text'])) {
            $data['certification_text'] = $data['certification_statement'];
        }
        unset($data['certification_statement']);

        $vendor = $trip->selectedVendor ?? $trip->vendor;
        $meta = $trip->metadata ?? [];

        $defaults = [
            'trip_id' => $trip->id,
            'vendor_id' => $vendor?->id,
            'issued_by' => $issuedBy,
            'status' => JobCompletionCertificate::STATUS_DRAFT,
            'reference_number' => $this->referenceService->generateReferenceNumberForTrip($trip),
            'po_number' => $data['po_number'] ?? ($meta['po_number'] ?? $trip->po_number ?? null),
            'certification_text' => $data['certification_text'] ?? $this->defaultCertificationParagraph($trip),
            'service_period_start' => $data['service_period_start'] ?? $trip->scheduled_departure_at?->format('Y-m-d'),
            'service_period_end' => $data['service_period_end'] ?? $trip->scheduled_arrival_at?->format('Y-m-d'),
            'currency' => $data['currency'] ?? 'NGN',
            'date_issued' => $data['date_issued'] ?? now()->toDateString(),
        ];

        $jcc = JobCompletionCertificate::create(array_merge($defaults, $data));

        foreach ($lineItemsInput as $idx => $row) {
            $this->createLineItemFromInput($jcc, $row, $idx);
        }

        $this->recomputeTotals($jcc);

        return $jcc->fresh(['lineItems', 'trip', 'vendor', 'issuedBy', 'approvedBy']);
    }

    private function defaultCertificationParagraph(Trip $trip): string
    {
        return "This is to certify that services for trip {$trip->trip_code} ({$trip->title}) were rendered as described below.";
    }

    /**
     * Suggested JCC line items from vendor portal data (no JCC persisted).
     *
     * @return array{line_items: array<int, array<string, mixed>>}
     */
    public function suggestPrefillLineItems(Trip $trip): array
    {
        $trip->loadMissing(['selectedVendor', 'vendor', 'vendorSubmissions.vendor']);

        $vendor = $trip->selectedVendor ?? $trip->vendor;
        $linkedPo = $this->resolveLinkedPo($trip, null);
        $lineItems = [];
        $n = 1;

        $query = $trip->vendorSubmissions()
            ->whereIn('status', [
                TripVendorSubmission::STATUS_SUBMITTED,
                TripVendorSubmission::STATUS_APPROVED,
            ])
            ->with('vendor');

        if ($trip->selected_vendor_id) {
            $query->where('vendor_id', $trip->selected_vendor_id);
        }

        $query->chunk(100, function ($submissions) use (&$lineItems, &$n, $trip) {
            foreach ($submissions as $submission) {
                $quantity = 1.0;
                $unitPrice = (float) ($submission->quoted_price ?? 0);
                $amount = round($quantity * $unitPrice, 2);

                $lineItems[] = DocumentDisplayPayload::withCamelCaseAliases([
                    'index' => $n - 1,
                    'serial_number' => $n,
                    'description' => trim("{$submission->vehicle_make} {$submission->vehicle_model}"),
                    'unit' => 'trip',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice > 0 ? $unitPrice : null,
                    'amount' => $amount > 0 ? $amount : null,
                    'trip_reference' => $trip->trip_code,
                    'service_date' => $trip->scheduled_departure_at?->format('Y-m-d'),
                    'remarks' => $submission->driver_name
                        ? 'Driver: '.$submission->driver_name.($submission->driver_phone ? " ({$submission->driver_phone})" : '')
                        : null,
                    'vendor_submission_id' => $submission->id,
                    'item_type' => JCCLineItem::ITEM_TYPE_VEHICLE,
                    'reference_number' => $submission->plate_number,
                ]);
                $n++;
            }
        });

        return DocumentDisplayPayload::withCamelCaseAliases([
            'reference_number' => $this->referenceService->generateReferenceNumberForTrip($trip),
            'vendor' => $this->mapVendor($vendor),
            'trip' => $this->mapTrip($trip),
            'linked_po' => $linkedPo,
            'certification_statement' => $this->defaultCertificationParagraph($trip),
            'currency' => 'NGN',
            'line_items' => $lineItems,
        ]);
    }

    /**
     * Update JCC details (while in DRAFT status)
     */
    public function updateJCC(JobCompletionCertificate $jcc, array $data): JobCompletionCertificate
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('Cannot update JCC that is not in draft status');
        }

        if (isset($data['certification_statement']) && ! isset($data['certification_text'])) {
            $data['certification_text'] = $data['certification_statement'];
        }
        unset($data['certification_statement']);

        $lineItemsInput = $data['line_items'] ?? null;
        unset($data['line_items']);

        $jcc->update($data);

        if (is_array($lineItemsInput)) {
            $jcc->lineItems()->delete();
            foreach ($lineItemsInput as $idx => $row) {
                $this->createLineItemFromInput($jcc, $row, $idx);
            }
        }

        $this->recomputeTotals($jcc);

        return $jcc->fresh(['lineItems', 'trip', 'vendor', 'issuedBy', 'approvedBy']);
    }

    /**
     * Submit the JCC for approval
     */
    public function submitJCC(JobCompletionCertificate $jcc, ?User $signatory = null): JobCompletionCertificate
    {
        if (!$jcc->isDraft()) {
            throw new \Exception('JCC must be in draft status to submit');
        }

        if ($jcc->lineItems()->count() === 0) {
            throw new \Exception('At least one line item is required');
        }

        $this->recomputeTotals($jcc);

        if ($signatory) {
            $metadata = $jcc->metadata ?? [];
            $metadata['issued_by_signatory'] = $this->buildSignatoryBlock($signatory, now());
            $jcc->update(['metadata' => $metadata, 'issued_by' => $signatory->id]);
        }

        $jcc->submit();

        return $jcc->fresh(['lineItems', 'trip', 'vendor', 'issuedBy', 'approvedBy']);
    }

    /**
     * Approve the JCC and close the trip
     */
    public function approveJCC(JobCompletionCertificate $jcc, int $approvedBy, ?string $remarks = null, ?User $approver = null): JobCompletionCertificate
    {
        if (!$jcc->isSubmitted()) {
            throw new \Exception('JCC must be submitted to be approved');
        }

        $approverUser = $approver ?? User::query()->find($approvedBy);
        if ($approverUser) {
            $metadata = $jcc->metadata ?? [];
            $metadata['approved_by_signatory'] = $this->buildSignatoryBlock($approverUser, now());
            $jcc->update(['metadata' => $metadata]);
        }

        $jcc->approve($approvedBy, $remarks);

        // Notify relevant stakeholders
        $trip = $jcc->trip;
        $primaryVendor = $trip->selectedVendor ?? $trip->vendor;
        $vendorUser = $primaryVendor?->users()->first();
        if ($vendorUser) {
            $vendorUser->notifyNow(
                new JobCompletionCertificateApprovedNotification($trip, $jcc)
            );
        }

        // Notify trip creator
        if ($trip->creator) {
            $trip->creator->notifyNow(new JobCompletionCertificateApprovedNotification($trip, $jcc));
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

        // Reorder remaining items using raw SQL to avoid N+1 updates
        $lineItems = $item->jcc->lineItems()
            ->orderBy('line_number')
            ->get(['id', 'line_number']);

        // Use a single raw SQL update to batch reorder all items
        foreach ($lineItems as $index => $lineItem) {
            $lineItem->update(['line_number' => $index + 1]);
        }
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

        $query = $trip->vendorSubmissions()
            ->whereIn('status', [
                TripVendorSubmission::STATUS_SUBMITTED,
                TripVendorSubmission::STATUS_APPROVED,
            ]);

        if ($trip->selected_vendor_id) {
            $query->where('vendor_id', $trip->selected_vendor_id);
        }

        $submissions = $query->get();

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
     * Full JCC record for client PDF display models and server PDF fallback.
     */
    public function getJCCSummary(JobCompletionCertificate $jcc): array
    {
        return $this->toDisplayRecord($jcc);
    }

    public function toDisplayRecord(JobCompletionCertificate $jcc): array
    {
        $jcc->loadMissing(['trip.selectedVendor', 'trip.vendor', 'vendor', 'issuedBy', 'approvedBy', 'lineItems']);

        $trip = $jcc->trip;
        $vendor = $jcc->vendor ?? $trip?->selectedVendor ?? $trip?->vendor;
        $metadata = $jcc->metadata ?? [];

        $lineItems = $jcc->lineItems()->orderBy('line_number')->get()->map(function (JCCLineItem $item) {
            return DocumentDisplayPayload::withCamelCaseAliases([
                'id' => $item->id,
                'line_number' => $item->line_number,
                'description' => DocumentDisplayPayload::nullIfEmpty($item->description),
                'unit' => DocumentDisplayPayload::nullIfEmpty($item->unit),
                'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'amount' => $item->amount !== null ? (float) $item->amount : null,
                'remarks' => DocumentDisplayPayload::nullIfEmpty($item->remarks),
                'item_type' => $item->item_type,
                'reference_number' => DocumentDisplayPayload::nullIfEmpty($item->reference_number),
            ]);
        })->all();

        $issuedBy = null;
        if ($jcc->isSubmitted() || $jcc->isApproved()) {
            $issuedBy = $metadata['issued_by_signatory']
                ?? ($jcc->issuedBy ? $this->buildSignatoryBlock($jcc->issuedBy, $jcc->issued_at) : null);
        }

        $approvedBy = null;
        if ($jcc->isApproved()) {
            $approvedBy = $metadata['approved_by_signatory']
                ?? ($jcc->approvedBy ? $this->buildSignatoryBlock($jcc->approvedBy, $jcc->approved_at) : null);
        }

        $payload = [
            'id' => $jcc->id,
            'reference_number' => $jcc->reference_number,
            'date_issued' => ($jcc->date_issued ?? $jcc->issued_at ?? $jcc->created_at)?->format('Y-m-d'),
            'status' => $jcc->status,
            'certification_statement' => DocumentDisplayPayload::nullIfEmpty($jcc->certification_text),
            'certification_text' => DocumentDisplayPayload::nullIfEmpty($jcc->certification_text),
            'vendor' => $this->mapVendor($vendor),
            'trip' => $trip ? $this->mapTrip($trip) : null,
            'linked_po' => $this->resolveLinkedPo($trip, $jcc),
            'line_items' => $lineItems,
            'subtotal' => $jcc->subtotal !== null ? (float) $jcc->subtotal : null,
            'vat' => $jcc->vat !== null ? (float) $jcc->vat : null,
            'total_amount' => $jcc->total_amount !== null ? (float) $jcc->total_amount : null,
            'currency' => $jcc->currency ?: 'NGN',
            'issued_by' => $issuedBy,
            'approved_by' => $approvedBy,
            'attachments' => $this->mapAttachments($jcc),
            'delivery_confirmed' => $jcc->delivery_confirmed,
            'remarks' => DocumentDisplayPayload::nullIfEmpty($jcc->remarks),
            'condition_of_goods' => DocumentDisplayPayload::nullIfEmpty($jcc->condition_of_goods),
            'approval_remarks' => DocumentDisplayPayload::nullIfEmpty($jcc->approval_remarks),
            'service_period_start' => $jcc->service_period_start?->format('Y-m-d'),
            'service_period_end' => $jcc->service_period_end?->format('Y-m-d'),
            'created_at' => $jcc->created_at?->toIso8601String(),
            'updated_at' => $jcc->updated_at?->toIso8601String(),
        ];

        return DocumentDisplayPayload::withCamelCaseAliases(
            DocumentDisplayPayload::nullifyEmptyStrings($payload) ?? []
        );
    }

    private function createLineItemFromInput(JobCompletionCertificate $jcc, array $row, int $idx): JCCLineItem
    {
        $details = $row['details'] ?? [];
        if (! empty($row['service_date'])) {
            $details['service_date'] = $row['service_date'];
        }

        $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 1.0;
        $unitPrice = isset($row['unit_price']) || isset($row['unitPrice'])
            ? (float) ($row['unit_price'] ?? $row['unitPrice'])
            : null;
        $amount = isset($row['amount'])
            ? (float) $row['amount']
            : ($unitPrice !== null ? round($quantity * $unitPrice, 2) : null);

        return $jcc->lineItems()->create([
            'line_number' => $row['line_number'] ?? ($idx + 1),
            'description' => $row['description'],
            'unit' => $row['unit'] ?? null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'item_type' => $row['item_type'] ?? JCCLineItem::ITEM_TYPE_VEHICLE,
            'details' => $details ?: null,
            'condition' => $row['condition'] ?? JCCLineItem::CONDITION_GOOD,
            'remarks' => $row['remarks'] ?? null,
            'reference_number' => $row['reference_number'] ?? $row['trip_reference'] ?? null,
            'vendor_submission_id' => $row['vendor_submission_id'] ?? null,
        ]);
    }

    private function recomputeTotals(JobCompletionCertificate $jcc): void
    {
        $subtotal = (float) $jcc->lineItems()->sum('amount');
        $vat = round($subtotal * 0.075, 2);
        $total = round($subtotal + $vat, 2);

        $jcc->update([
            'subtotal' => $subtotal > 0 ? $subtotal : null,
            'vat' => $subtotal > 0 ? $vat : null,
            'total_amount' => $subtotal > 0 ? $total : null,
            'currency' => $jcc->currency ?: 'NGN',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapVendor(?Vendor $vendor): ?array
    {
        if (! $vendor) {
            return null;
        }

        return DocumentDisplayPayload::withCamelCaseAliases([
            'id' => $vendor->id,
            'name' => DocumentDisplayPayload::nullIfEmpty($vendor->name),
            'address' => DocumentDisplayPayload::nullIfEmpty($vendor->address),
            'contact_person' => DocumentDisplayPayload::nullIfEmpty($vendor->contact_person),
            'phone' => DocumentDisplayPayload::nullIfEmpty($vendor->phone),
            'email' => DocumentDisplayPayload::nullIfEmpty($vendor->email),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapTrip(Trip $trip): ?array
    {
        return DocumentDisplayPayload::withCamelCaseAliases([
            'id' => $trip->id,
            'reference' => DocumentDisplayPayload::nullIfEmpty($trip->trip_code),
            'origin' => DocumentDisplayPayload::nullIfEmpty($trip->origin),
            'destination' => DocumentDisplayPayload::nullIfEmpty($trip->destination),
            'departure_date' => $trip->scheduled_departure_at?->format('Y-m-d'),
            'return_date' => $trip->scheduled_arrival_at?->format('Y-m-d'),
            'purpose' => DocumentDisplayPayload::nullIfEmpty($trip->purpose),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLinkedPo(?Trip $trip, ?JobCompletionCertificate $jcc): ?array
    {
        $poNumber = DocumentDisplayPayload::nullIfEmpty($jcc?->po_number ?? $trip?->po_number);
        if ($poNumber === null) {
            return null;
        }

        $meta = is_array($trip?->metadata) ? $trip->metadata : [];

        return DocumentDisplayPayload::withCamelCaseAliases([
            'id' => DocumentDisplayPayload::nullIfEmpty($meta['po_id'] ?? null),
            'po_number' => $poNumber,
            'date' => DocumentDisplayPayload::nullIfEmpty($meta['po_date'] ?? $meta['po_generated_at'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSignatoryBlock(User $user, $signedAt): array
    {
        return DocumentDisplayPayload::withCamelCaseAliases([
            'name' => DocumentDisplayPayload::nullIfEmpty($user->name),
            'title' => DocumentDisplayPayload::nullIfEmpty($user->department ?? ucwords(str_replace('_', ' ', (string) ($user->role ?? '')))),
            'signature_url' => SignatureUrls::forUser($user),
            'signed_at' => $signedAt ? Carbon::parse($signedAt)->toIso8601String() : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapAttachments(JobCompletionCertificate $jcc): array
    {
        $disk = config('filesystems.logistics_documents_disk', config('filesystems.documents_disk', 's3'));
        $attachments = [];

        foreach ($jcc->attachments ?? [] as $attachment) {
            $path = $attachment['file_path'] ?? null;
            if (! $path) {
                continue;
            }

            $fileUrl = null;
            try {
                if ($disk === 's3') {
                    $fileUrl = Storage::disk($disk)->temporaryUrl($path, now()->addHours(168));
                } else {
                    $fileUrl = Storage::disk($disk)->url($path);
                }
            } catch (\Throwable) {
                $fileUrl = null;
            }

            $attachments[] = DocumentDisplayPayload::withCamelCaseAliases([
                'file_name' => DocumentDisplayPayload::nullIfEmpty($attachment['file_name'] ?? null),
                'file_path' => $path,
                'file_url' => $fileUrl,
                'uploaded_at' => isset($attachment['uploaded_at'])
                    ? Carbon::parse($attachment['uploaded_at'])->toIso8601String()
                    : null,
            ]);
        }

        return $attachments;
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
