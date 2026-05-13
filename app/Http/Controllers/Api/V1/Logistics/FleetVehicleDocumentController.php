<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreVehicleFleetDocumentRequest;
use App\Models\Logistics\Document;
use App\Models\Logistics\Vehicle;
use App\Services\Logistics\AuditLogger;
use App\Support\FleetVehicleLookup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class FleetVehicleDocumentController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    private function storageDisk(): string
    {
        return config('filesystems.logistics_documents_disk', config('filesystems.default'));
    }

    public function store(StoreVehicleFleetDocumentRequest $request, string|int $vehicleId)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        Document::query()
            ->where('documentable_type', Vehicle::class)
            ->where('documentable_id', $vehicle->id)
            ->where('document_type', $request->document_type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $uploaded = $request->file('file')
            ?? $request->file('document')
            ?? $request->file('attachment')
            ?? $request->file('upload');

        if (!$uploaded) {
            return $this->error('A file upload is required.', 'VALIDATION_ERROR', 422, [
                'file' => ['Please attach a PDF or image file.'],
            ]);
        }

        $path = $uploaded->store('logistics/documents', $this->storageDisk());

        $expiresAt = null;
        if ($request->filled('expiry_date')) {
            $expiresAt = Carbon::parse($request->expiry_date)->startOfDay();
        }

        $document = Document::create([
            'documentable_type' => Vehicle::class,
            'documentable_id' => $vehicle->id,
            'document_type' => $request->document_type,
            'file_path' => $path,
            'file_name' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getMimeType(),
            'size' => $uploaded->getSize(),
            'expires_at' => $expiresAt,
            'uploaded_by' => $request->user()?->id,
            'is_active' => true,
            'metadata' => [],
        ]);

        $this->auditLogger->log('vehicle_document_uploaded', $request->user(), 'document', (string) $document->id, $document->toArray(), $request);

        return $this->success([
            'document' => $document,
        ], 201);
    }

    public function index(string|int $vehicleId)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($vehicleId);
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

        $flat = Document::query()
            ->where('documentable_type', Vehicle::class)
            ->where('documentable_id', $vehicle->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'vehicle_id' => $vehicle->id,
            'documents_by_type' => $grouped,
            'documents' => $flat,
        ]);
    }

    public function destroy(string|int $vehicleId, int $documentId)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($vehicleId);
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

        try {
            Storage::disk($this->storageDisk())->delete($document->file_path);
        } catch (\Throwable $e) {
            \Log::warning('Failed to remove vehicle document file from storage', [
                'document_id' => $document->id,
                'file_path' => $document->file_path,
                'error' => $e->getMessage(),
            ]);
        }

        $document->delete();

        return $this->success([
            'deleted' => true,
        ]);
    }
}
