<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\BulkUploadMaterialsRequest;
use App\Http\Requests\Logistics\StoreMaterialRequest;
use App\Models\Logistics\Material;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\UploadService;
use Illuminate\Http\Request;

class MaterialController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger, private UploadService $uploadService)
    {
    }

    public function store(StoreMaterialRequest $request)
    {
        $material = Material::create($request->validated());

        $this->auditLogger->log('material_created', $request->user(), 'material', (string) $material->id, $material->toArray(), $request);

        return $this->success([
            'material' => $material,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Material::query();

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        return $this->success([
            'materials' => $query->paginate(20),
        ]);
    }

    public function show(int $id)
    {
        $material = Material::with('conditionHistory')->find($id);

        if (!$material) {
            return $this->error('Material not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'material' => $material,
        ]);
    }

    public function listByTrip(int $tripId)
    {
        $materials = Material::where('trip_id', $tripId)->paginate(20);

        return $this->success([
            'materials' => $materials,
        ]);
    }

    public function bulkUpload(BulkUploadMaterialsRequest $request)
    {
        [$validRows, $errors] = $this->uploadService->validateRows($request->rows, ['material_code', 'name', 'quantity']);

        $created = [];
        foreach ($validRows as $row) {
            $created[] = Material::create([
                'material_code' => $row['material_code'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'trip_id' => $row['trip_id'] ?? null,
                'unit' => $row['unit'] ?? null,
            ]);
        }

        return $this->success([
            'created' => $created,
            'errors' => $errors,
        ], count($errors) > 0 ? 207 : 201);
    }

    public function destroy(int $id, Request $request)
    {
        $material = Material::find($id);

        if (!$material) {
            return $this->error('Material not found', 'NOT_FOUND', 404);
        }

        $this->auditLogger->log('material_deleted', $request->user(), 'material', (string) $material->id, 'Material deleted', $material->toArray(), $request);
        
        $material->delete();

        return $this->success([
            'message' => 'Material deleted successfully',
            'material_id' => $id,
        ]);
    }
}
