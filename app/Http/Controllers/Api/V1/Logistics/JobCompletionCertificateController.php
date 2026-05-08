<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\ApproveJobCompletionCertificateRequest;
use App\Http\Requests\Logistics\StoreJobCompletionCertificateRequest;
use App\Http\Requests\Logistics\UpdateJobCompletionCertificateRequest;
use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\Trip;
use App\Services\Logistics\JobCompletionCertificateService;
use Illuminate\Http\Request;

class JobCompletionCertificateController extends ApiController
{
    public function __construct(
        private JobCompletionCertificateService $jccService,
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
