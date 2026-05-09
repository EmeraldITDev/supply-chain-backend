<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\ApproveJobCompletionCertificateRequest;
use App\Http\Requests\Logistics\StoreJobCompletionCertificateRequest;
use App\Http\Requests\Logistics\StoreJCCLineItemRequest;
use App\Http\Requests\Logistics\UpdateJobCompletionCertificateRequest;
use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\JCCLineItem;
use App\Models\Logistics\Trip;
use App\Services\Logistics\JobCompletionCertificateService;
use App\Services\Logistics\JCCPdfService;
use Illuminate\Http\Request;

class JobCompletionCertificateController extends ApiController
{
    public function __construct(
        private JobCompletionCertificateService $jccService,
        private JCCPdfService $pdfService,
    ) {
    }

    /**
     * Create a new JCC for a trip
     */
    public function store(StoreJobCompletionCertificateRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $jcc = $this->jccService->createJCC($trip, $request->user()->id, $data);

            return $this->success([
                'message' => 'Job Completion Certificate created successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create JCC: ' . $e->getMessage(), 'CREATION_FAILED', 400);
        }
    }

    /**
     * Get JCC for a trip
     */
    public function show(int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        return $this->success([
            'jcc' => $this->jccService->getJCCSummary($jcc),
        ]);
    }

    /**
     * Update JCC (while in DRAFT status)
     */
    public function update(UpdateJobCompletionCertificateRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $jcc = $this->jccService->updateJCC($jcc, $data);

            return $this->success([
                'message' => 'JCC updated successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to update JCC: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Add a line item to JCC
     */
    public function addLineItem(StoreJCCLineItemRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $lineItem = $this->jccService->addLineItem($jcc, $data);

            return $this->success([
                'message' => 'Line item added successfully',
                'line_item' => [
                    'id' => $lineItem->id,
                    'line_number' => $lineItem->line_number,
                    'description' => $lineItem->description,
                    'item_type' => $lineItem->item_type,
                    'condition' => $lineItem->condition,
                    'remarks' => $lineItem->remarks,
                    'reference_number' => $lineItem->reference_number,
                ],
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to add line item: ' . $e->getMessage(), 'ADD_FAILED', 400);
        }
    }

    /**
     * Update a line item
     */
    public function updateLineItem(StoreJCCLineItemRequest $request, int $tripId, int $lineItemId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        $lineItem = JCCLineItem::find($lineItemId);

        if (!$lineItem || $lineItem->jcc_id !== $jcc->id) {
            return $this->error('Line item not found', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $lineItem = $this->jccService->updateLineItem($lineItem, $data);

            return $this->success([
                'message' => 'Line item updated successfully',
                'line_item' => [
                    'id' => $lineItem->id,
                    'line_number' => $lineItem->line_number,
                    'description' => $lineItem->description,
                    'item_type' => $lineItem->item_type,
                    'condition' => $lineItem->condition,
                    'remarks' => $lineItem->remarks,
                    'reference_number' => $lineItem->reference_number,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to update line item: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Delete a line item
     */
    public function deleteLineItem(int $tripId, int $lineItemId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        $lineItem = JCCLineItem::find($lineItemId);

        if (!$lineItem || $lineItem->jcc_id !== $jcc->id) {
            return $this->error('Line item not found', 'NOT_FOUND', 404);
        }

        try {
            $this->jccService->deleteLineItem($lineItem);

            return $this->success([
                'message' => 'Line item deleted successfully',
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to delete line item: ' . $e->getMessage(), 'DELETE_FAILED', 400);
        }
    }

    /**
     * Prefill JCC with vendor submissions
     *
     * Converts vendor portal submissions into ready-to-use line item suggestions
     */
    public function prefill(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            $lineItems = $this->jccService->prefillFromVendorSubmissions($jcc, $tripId);

            return $this->success([
                'message' => 'JCC prefilled with vendor submissions successfully',
                'prefilled_items_count' => count($lineItems),
                'line_items' => array_map(function ($item) {
                    return [
                        'id' => $item->id,
                        'line_number' => $item->line_number,
                        'description' => $item->description,
                        'item_type' => $item->item_type,
                        'condition' => $item->condition,
                        'reference_number' => $item->reference_number,
                        'vendor_submission_id' => $item->vendor_submission_id,
                    ];
                }, $lineItems),
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to prefill JCC: ' . $e->getMessage(), 'PREFILL_FAILED', 400);
        }
    }

    /**
     * Submit JCC for approval
     */
    public function submit(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            $jcc = $this->jccService->submitJCC($jcc);

            return $this->success([
                'message' => 'JCC submitted for approval successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to submit JCC: ' . $e->getMessage(), 'SUBMIT_FAILED', 400);
        }
    }

    /**
     * Approve JCC and close trip
     */
    public function approve(ApproveJobCompletionCertificateRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $jcc = $this->jccService->approveJCC(
                $jcc,
                $request->user()->id,
                $data['approval_remarks'] ?? null
            );

            return $this->success([
                'message' => 'JCC approved and trip closed successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
                'trip_status' => $jcc->trip->status,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to approve JCC: ' . $e->getMessage(), 'APPROVAL_FAILED', 400);
        }
    }

    /**
     * Generate PDF of JCC
     *
     * Returns PDF with explicit layout requirements:
     * - Header: Title and reference number
     * - Trip Info: Code, date, vendor details
     * - Line Items Table: Vehicle/service details with condition
     * - Delivery Confirmation: Yes/No with remarks
     * - Condition of Goods: Text field
     * - Signatory Section: Issued by, Approved by, Witness
     * - Footer: Reference number, printed date
     */
    public function generatePdf(int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        try {
            return $this->pdfService->downloadPdf($jcc);
        } catch (\Exception $e) {
            return $this->error('Failed to generate PDF: ' . $e->getMessage(), 'PDF_FAILED', 400);
        }
    }

    /**
     * Get PDF layout structure metadata
     *
     * Returns the exact layout specifications for the physical document
     */
    public function getPdfLayout(int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        return $this->success([
            'layout_structure' => $this->pdfService->getLayoutStructure(),
        ]);
    }

    /**
     * Upload attachments to JCC
     */
    public function uploadAttachment(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $jcc = $this->jccService->getJCCForTrip($trip);

        if (!$jcc) {
            return $this->error('No JCC found for this trip', 'NOT_FOUND', 404);
        }

        // Validate file
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
            'file_name' => 'nullable|string|max:255',
        ]);

        try {
            $file = $request->file('file');
            $path = $file->store('jcc-attachments/' . $tripId, 's3');

            // Add attachment
            $this->jccService->addAttachment(
                $jcc,
                $path,
                $request->input('file_name') ?? $file->getClientOriginalName()
            );

            return $this->success([
                'message' => 'Attachment uploaded successfully',
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to upload attachment: ' . $e->getMessage(), 'UPLOAD_FAILED', 400);
        }
    }

    /**
     * Get all JCCs with optional filters
     */
    public function index(Request $request)
    {
        try {
            $jccs = $this->jccService->getAllJCCs(
                status: $request->input('status'),
                dateFrom: $request->input('date_from'),
                dateTo: $request->input('date_to'),
                perPage: $request->input('per_page', 15)
            );

            return $this->success([
                'jccs' => array_map(
                    fn($jcc) => $this->jccService->getJCCSummary($jcc),
                    $jccs->items()
                ),
                'pagination' => [
                    'total' => $jccs->total(),
                    'per_page' => $jccs->perPage(),
                    'current_page' => $jccs->currentPage(),
                    'last_page' => $jccs->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve JCCs: ' . $e->getMessage(), 'FETCH_FAILED', 400);
        }
    }
}
