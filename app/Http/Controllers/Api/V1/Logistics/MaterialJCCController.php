<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreMaterialJCCRequest;
use App\Http\Requests\Logistics\UpdateMaterialJCCRequest;
use App\Http\Requests\Logistics\ApproveMaterialJCCRequest;
use App\Models\Logistics\MaterialMovement;
use App\Models\Logistics\MaterialJCC;
use App\Models\Logistics\MaterialJCCLineItem;
use App\Services\Logistics\MaterialJCCService;
use App\Services\Logistics\MaterialJCCPdfService;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;

class MaterialJCCController extends ApiController
{
    public function __construct(
        private MaterialJCCService $jccService,
        private MaterialJCCPdfService $pdfService,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a MaterialJCC for a material movement.
     * Auto-generates reference number and pulls vendor/movement details.
     */
    public function store(StoreMaterialJCCRequest $request, string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        try {
            $data = $request->validated();
            $jcc = $this->jccService->createJCC($material, $request->user()->id, $data);

            $this->auditLogger->log(
                'material_jcc_created',
                $request->user(),
                'material_jcc',
                $jcc->id,
                $jcc->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Job Completion Certificate created successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create JCC: ' . $e->getMessage(), 'CREATION_FAILED', 400);
        }
    }

    /**
     * Retrieve the JCC with all line items for a material movement.
     */
    public function show(string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        $jcc = $material->jcc()->with('lineItems', 'vendor', 'issuedBy', 'approvedBy')->first();

        if (!$jcc) {
            return $this->error('No JCC found for this material movement', 'NOT_FOUND', 404);
        }

        return $this->success([
            'jcc' => $this->jccService->getJCCSummary($jcc),
        ]);
    }

    /**
     * Update JCC header and line items while in DRAFT status.
     */
    public function update(UpdateMaterialJCCRequest $request, string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        $jcc = $material->jcc()->first();

        if (!$jcc) {
            return $this->error('No JCC found for this material movement', 'NOT_FOUND', 404);
        }

        if (!$jcc->isDraft()) {
            return $this->error(
                'Only draft JCCs can be updated',
                'UPDATE_NOT_ALLOWED',
                422
            );
        }

        try {
            $data = $request->validated();
            $jcc = $this->jccService->updateJCC($jcc, $data);

            $this->auditLogger->log(
                'material_jcc_updated',
                $request->user(),
                'material_jcc',
                $jcc->id,
                $jcc->toArray(),
                $request
            );

            return $this->success([
                'message' => 'JCC updated successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to update JCC: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Submit JCC for approval (advance to SUBMITTED status).
     */
    public function submit(Request $request, string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        $jcc = $material->jcc()->first();

        if (!$jcc) {
            return $this->error('No JCC found for this material movement', 'NOT_FOUND', 404);
        }

        if (!$jcc->isDraft()) {
            return $this->error(
                'Only draft JCCs can be submitted',
                'SUBMIT_NOT_ALLOWED',
                422
            );
        }

        try {
            $jcc->submit();

            $this->auditLogger->log(
                'material_jcc_submitted',
                $request->user(),
                'material_jcc',
                $jcc->id,
                $jcc->toArray(),
                $request
            );

            return $this->success([
                'message' => 'JCC submitted for approval successfully',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to submit JCC: ' . $e->getMessage(), 'SUBMIT_FAILED', 400);
        }
    }

    /**
     * Approve JCC and close material movement.
     */
    public function approve(ApproveMaterialJCCRequest $request, string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        $jcc = $material->jcc()->first();

        if (!$jcc) {
            return $this->error('No JCC found for this material movement', 'NOT_FOUND', 404);
        }

        if (!$jcc->isSubmitted()) {
            return $this->error(
                'Only submitted JCCs can be approved',
                'APPROVE_NOT_ALLOWED',
                422
            );
        }

        try {
            $jcc->approve($request->user());

            $this->auditLogger->log(
                'material_jcc_approved',
                $request->user(),
                'material_jcc',
                $jcc->id,
                $jcc->toArray(),
                $request
            );

            return $this->success([
                'message' => 'JCC approved successfully and material movement closed',
                'jcc' => $this->jccService->getJCCSummary($jcc),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to approve JCC: ' . $e->getMessage(), 'APPROVE_FAILED', 400);
        }
    }

    /**
     * Get PDF of the MaterialJCC.
     */
    public function pdf(string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        $jcc = $material->jcc()->with('lineItems', 'vendor', 'issuedBy')->first();

        if (!$jcc) {
            return $this->error('No JCC found for this material movement', 'NOT_FOUND', 404);
        }

        try {
            return $this->pdfService->generatePdf($jcc);
        } catch (\Exception $e) {
            return $this->error('Failed to generate PDF: ' . $e->getMessage(), 'PDF_GENERATION_FAILED', 400);
        }
    }

    /**
     * Get suggested line items derived from material movement record.
     */
    public function prefill(string $materialId)
    {
        $material = MaterialMovement::find($materialId);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        try {
            $lineItems = $this->jccService->generatePrefillLineItems($material);

            return $this->success([
                'line_items' => $lineItems,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to generate prefill data: ' . $e->getMessage(), 'PREFILL_FAILED', 400);
        }
    }
}
