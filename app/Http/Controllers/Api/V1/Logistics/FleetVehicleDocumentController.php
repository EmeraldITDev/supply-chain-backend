<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreVehicleFleetDocumentRequest;
use App\Models\Logistics\Document;
use App\Models\Logistics\Vehicle;
use App\Services\Logistics\AuditLogger;
use Carbon\Carbon;

class FleetVehicleDocumentController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    private function storageDisk(): string
    {
        return config('filesystems.logistics_documents_disk', config('filesystems.default'));
    }

    public function store(StoreVehicleFleetDocumentRequest $request, int $vehicleId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        Document::query()
            ->where('documentable_type', Vehicle::class)
            ->where('documentable_id', $vehicle->id)
            ->where('document_type', $request->document_type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $path = $request->file('file')->store('logistics/documents', $this->storageDisk());

        $document = Document::create([
            'documentable_type' => Vehicle::class,
            'documentable_id' => $vehicle->id,
            'document_type' => $request->document_type,
            'file_path' => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getMimeType(),
            'size' => $request->file('file')->getSize(),
            'expires_at' => Carbon::parse($request->expiry_date)->startOfDay(),
            'uploaded_by' => $request->user()?->id,
            'is_active' => true,
            'metadata' => [],
        ]);

        $this->auditLogger->log('vehicle_document_uploaded', $request->user(), 'document', (string) $document->id, $document->toArray(), $request);

        return $this->success([
            'document' => $document,
        ], 201);
    }

    public function index(int $vehicleId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $grouped = [];
        foreach (StoreVehicleFleetDocumentRequest::DOCUMENT_TYPES as $type) {
            $docs = Document::query()
                ->where('documentable_type', Vehicle::class)
                ->where('documentable_id', $vehicle->id)
                ->where('document_type', $type)
                ->orderByDesc('created_at')
                ->get();

            $latestActive = $docs->firstWhere('is_active', true);

            $grouped[$type] = [
                'latest_active' => $latestActive,
                'all_versions' => $docs->values(),
            ];
        }

        return $this->success([
            'vehicle_id' => $vehicle->id,
            'documents_by_type' => $grouped,
        ]);
    }

    public function destroy(int $vehicleId, int $documentId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $document = Document::query()
            ->where('id', $documentId)
            ->where('documentable_type', Vehicle::class)
            ->where('documentable_id', $vehicle->id)
            ->first();

        if (!$document) {
            return $this->error('Document not found', 'NOT_FOUND', 404);
        }

        $document->is_active = false;
        $document->save();

        return $this->success([
            'document' => $document,
            'deactivated' => true,
        ]);
    }
}
