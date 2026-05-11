<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Enums\MaterialStatus;
use App\Http\Requests\Logistics\StoreMaterialMovementRequest;
use App\Http\Requests\Logistics\UpdateMaterialMovementRequest;
use App\Http\Requests\Logistics\MarkMaterialInTransitRequest;
use App\Http\Requests\Logistics\MarkMaterialDeliveredRequest;
use App\Models\Logistics\MaterialMovement;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;

class MaterialMovementController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    /**
     * Create a new material movement record.
     */
    public function store(StoreMaterialMovementRequest $request)
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->id;

            $material = MaterialMovement::create($data);

            $this->auditLogger->log(
                'material_movement_created',
                $request->user(),
                'material_movement',
                $material->id,
                $material->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Material movement created successfully',
                'material' => $material,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create material movement: ' . $e->getMessage(), 'CREATION_FAILED', 400);
        }
    }

    /**
     * List all material movements (filterable by status, category, date range, destination).
     */
    public function index(Request $request)
    {
        $query = MaterialMovement::query();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by destination
        if ($request->filled('destination')) {
            $query->where('destination', 'like', '%' . $request->destination . '%');
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->whereDate('expected_pickup_datetime', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('expected_delivery_datetime', '<=', $request->end_date);
        }

        // Filter by vendor
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        $materials = $query->with(['vendor', 'createdBy', 'jcc'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->success([
            'materials' => $materials,
        ]);
    }

    /**
     * Get a single material movement with full details.
     */
    public function show(string $id)
    {
        $material = MaterialMovement::with(['vendor', 'createdBy', 'updatedByUser', 'jcc', 'jcc.lineItems'])
            ->find($id);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'material' => $material,
        ]);
    }

    /**
     * Update a material movement (while status is PENDING or IN_TRANSIT).
     */
    public function update(UpdateMaterialMovementRequest $request, string $id)
    {
        $material = MaterialMovement::find($id);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        // Check if material can be updated
        if (!$material->isPending() && !$material->isInTransit()) {
            return $this->error(
                'Material movement cannot be updated in its current status',
                'UPDATE_NOT_ALLOWED',
                422
            );
        }

        try {
            $data = $request->validated();
            $data['updated_by'] = $request->user()->id;

            $material->update($data);

            $this->auditLogger->log(
                'material_movement_updated',
                $request->user(),
                'material_movement',
                $material->id,
                $material->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Material movement updated successfully',
                'material' => $material,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to update material movement: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Soft-delete material movement (set status to CANCELLED).
     */
    public function destroy(string $id, Request $request)
    {
        $material = MaterialMovement::find($id);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        try {
            $material->cancel();

            $this->auditLogger->log(
                'material_movement_cancelled',
                $request->user(),
                'material_movement',
                $material->id,
                $material->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Material movement cancelled successfully',
                'material_id' => $id,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to cancel material movement: ' . $e->getMessage(), 'CANCEL_FAILED', 400);
        }
    }

    /**
     * Advance status to IN_TRANSIT and record actual pickup datetime.
     */
    public function markInTransit(MarkMaterialInTransitRequest $request, string $id)
    {
        $material = MaterialMovement::find($id);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        if (!$material->isPending()) {
            return $this->error(
                'Only pending materials can be marked as in transit',
                'STATUS_INVALID',
                422
            );
        }

        try {
            $data = $request->validated();
            $material->markInTransit($data['actual_pickup_datetime']);

            $this->auditLogger->log(
                'material_movement_in_transit',
                $request->user(),
                'material_movement',
                $material->id,
                $material->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Material marked as in transit successfully',
                'material' => $material,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to mark material as in transit: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }

    /**
     * Advance status to DELIVERED and record actual delivery datetime.
     * Note: This should only be done after JCC is approved, but the endpoint allows direct marking.
     */
    public function markDelivered(MarkMaterialDeliveredRequest $request, string $id)
    {
        $material = MaterialMovement::find($id);

        if (!$material) {
            return $this->error('Material movement not found', 'NOT_FOUND', 404);
        }

        if (!$material->isInTransit()) {
            return $this->error(
                'Only materials in transit can be marked as delivered',
                'STATUS_INVALID',
                422
            );
        }

        try {
            $data = $request->validated();
            $material->markDelivered($data['actual_delivery_datetime']);

            $this->auditLogger->log(
                'material_movement_delivered',
                $request->user(),
                'material_movement',
                $material->id,
                $material->toArray(),
                $request
            );

            return $this->success([
                'message' => 'Material marked as delivered successfully',
                'material' => $material,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to mark material as delivered: ' . $e->getMessage(), 'UPDATE_FAILED', 400);
        }
    }
}
