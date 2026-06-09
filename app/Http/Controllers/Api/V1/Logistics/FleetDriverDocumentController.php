<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreFleetDriverDocumentRequest;
use App\Models\Logistics\Document;
use App\Models\Logistics\FleetDriver;
use App\Services\Logistics\AuditLogger;
use App\Support\FleetDocumentExpiryTier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FleetDriverDocumentController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    private function storageDisk(): string
    {
        return config('filesystems.logistics_documents_disk', config('filesystems.default'));
    }

    private function findDriver(int|string $driverId): ?FleetDriver
    {
        return FleetDriver::query()->find($driverId);
    }

    public function store(StoreFleetDriverDocumentRequest $request, int|string $driverId)
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        Document::query()
            ->where('documentable_type', FleetDriver::class)
            ->where('documentable_id', $driver->id)
            ->where('document_type', $request->document_type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $uploaded = $request->file('file')
            ?? $request->file('document')
            ?? $request->file('attachment')
            ?? $request->file('upload');

        if (! $uploaded) {
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
            'documentable_type' => FleetDriver::class,
            'documentable_id' => $driver->id,
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

        $this->auditLogger->log(
            'driver_document_uploaded',
            $request->user(),
            'document',
            (string) $document->id,
            $document->toArray(),
            $request
        );

        return $this->success([
            'document' => $this->presentDocument($document),
        ], 201);
    }

    public function index(int|string $driverId)
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        $grouped = [];
        foreach (StoreFleetDriverDocumentRequest::DOCUMENT_TYPES as $type) {
            $docs = Document::query()
                ->where('documentable_type', FleetDriver::class)
                ->where('documentable_id', $driver->id)
                ->where('document_type', $type)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Document $doc) => $this->presentDocument($doc));

            $latestActive = $docs->first(fn (array $row) => ($row['is_active'] ?? false) === true);

            $grouped[$type] = [
                'latest_active' => $latestActive,
                'all_versions' => $docs->values(),
            ];
        }

        $flat = Document::query()
            ->where('documentable_type', FleetDriver::class)
            ->where('documentable_id', $driver->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Document $doc) => $this->presentDocument($doc));

        return $this->success([
            'driver_id' => $driver->id,
            'documents_by_type' => $grouped,
            'documents' => $flat->values(),
        ]);
    }

    public function destroy(Request $request, int|string $driverId, ?int $documentId = null)
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        $resolvedDocumentId = $documentId
            ?? $request->input('document_id')
            ?? $request->input('documentId');

        if ($resolvedDocumentId === null || $resolvedDocumentId === '') {
            return $this->error('Document id is required', 'VALIDATION_ERROR', 422);
        }

        $document = Document::query()
            ->where('id', (int) $resolvedDocumentId)
            ->where('documentable_type', FleetDriver::class)
            ->where('documentable_id', $driver->id)
            ->first();

        if (! $document) {
            return $this->error('Document not found', 'NOT_FOUND', 404);
        }

        try {
            Storage::disk($this->storageDisk())->delete($document->file_path);
        } catch (\Throwable $e) {
            \Log::warning('Failed to remove driver document file from storage', [
                'document_id' => $document->id,
                'file_path' => $document->file_path,
                'error' => $e->getMessage(),
            ]);
        }

        $document->delete();

        $this->auditLogger->log(
            'driver_document_deleted',
            $request->user(),
            'document',
            (string) $resolvedDocumentId,
            ['driver_id' => $driver->id],
            $request
        );

        return $this->success([
            'deleted' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDocument(Document $document): array
    {
        $tier = FleetDocumentExpiryTier::forExpiryDate($document->expires_at);

        return array_merge($document->toArray(), [
            'expiry_date' => $document->expires_at?->format('Y-m-d'),
            'expiryDate' => $document->expires_at?->format('Y-m-d'),
            'document_type' => $document->document_type,
            'documentType' => $document->document_type,
        ], $tier);
    }
}
