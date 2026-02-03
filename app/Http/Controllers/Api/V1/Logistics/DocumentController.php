<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreDocumentRequest;
use App\Models\Logistics\Document;
use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;
use App\Models\Logistics\Vehicle;
use App\Models\Vendor;
use App\Services\Logistics\AuditLogger;
use Illuminate\Support\Facades\Storage;

class DocumentController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    private function storageDisk(): string
    {
        return config('filesystems.logistics_documents_disk', config('filesystems.default'));
    }

    public function store(StoreDocumentRequest $request)
    {
        $entityType = $request->entity_type;
        $entityId = (int) $request->entity_id;

        $documentable = match ($entityType) {
            'vendor' => Vendor::find($entityId),
            'trip' => Trip::find($entityId),
            'journey' => Journey::find($entityId),
            'vehicle' => Vehicle::find($entityId),
            default => null,
        };

        if (!$documentable) {
            return $this->error('Entity not found', 'NOT_FOUND', 404);
        }

        $path = $request->file('file')->store('logistics/documents', $this->storageDisk());

        $document = Document::create([
            'documentable_type' => $documentable::class,
            'documentable_id' => $documentable->id,
            'document_type' => $request->document_type,
            'file_path' => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getMimeType(),
            'size' => $request->file('file')->getSize(),
            'expires_at' => $request->input('expires_at'),
            'issued_at' => $request->input('issued_at'),
            'uploaded_by' => $request->user()?->id,
            'metadata' => $request->input('metadata', []),
        ]);

        $this->auditLogger->log('document_uploaded', $request->user(), 'document', (string) $document->id, $document->toArray(), $request);

        return $this->success([
            'document' => $document,
        ], 201);
    }

    public function list(string $entityType, int $entityId)
    {
        $class = match ($entityType) {
            'vendor' => Vendor::class,
            'trip' => Trip::class,
            'journey' => Journey::class,
            'vehicle' => Vehicle::class,
            default => null,
        };

        if (!$class) {
            return $this->error('Invalid entity type', 'VALIDATION_ERROR', 422);
        }

        $documents = Document::where('documentable_type', $class)
            ->where('documentable_id', $entityId)
            ->paginate(20);

        return $this->success([
            'documents' => $documents,
        ]);
    }

    public function destroy(int $id)
    {
        $document = Document::find($id);

        if (!$document) {
            return $this->error('Document not found', 'NOT_FOUND', 404);
        }

        Storage::disk($this->storageDisk())->delete($document->file_path);
        $document->delete();

        return $this->success(['deleted' => true]);
    }
}
