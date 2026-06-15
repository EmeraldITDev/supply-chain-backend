<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Support\DocumentDisplayPayload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcurementDocumentService
{
    public function listForMrf(MRF $mrf, ?string $type = null, bool $activeOnly = true)
    {
        $query = ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->with(['uploader:id,name,email', 'vendor'])
            ->orderByDesc('uploaded_at');

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * @return array{
     *     documents: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     documentsByType: array<string, list<array<string, mixed>>>,
     *     activeByType: array<string, array<string, mixed>|null>
     * }
     */
    public function listGroupedForMrf(MRF $mrf, ?string $type = null, bool $activeOnly = true): array
    {
        $documents = $this->listForMrf($mrf, $type, $activeOnly)
            ->map(fn ($doc) => $this->transform($doc));

        $byType = [];
        $activeByType = [];

        foreach (ProcurementDocument::TYPES as $docType) {
            $byType[$docType] = [];
            $activeByType[$docType] = null;
        }

        foreach ($documents as $document) {
            $docType = (string) ($document['type'] ?? 'other');
            if (! isset($byType[$docType])) {
                $byType[$docType] = [];
            }

            $byType[$docType][] = $document;

            if (($document['isActive'] ?? false) && $activeByType[$docType] === null) {
                $activeByType[$docType] = $document;
            }
        }

        foreach ($byType as $docType => $items) {
            if ($items === []) {
                unset($byType[$docType]);
            }
        }

        foreach ($activeByType as $docType => $active) {
            if ($active === null) {
                unset($activeByType[$docType]);
            }
        }

        return [
            'documents' => $documents,
            'documentsByType' => $byType,
            'activeByType' => $activeByType,
        ];
    }

    public function storeBinaryContent(
        MRF $mrf,
        string $binaryContent,
        string $originalFileName,
        string $type,
        User $user,
        ?int $vendorId = null,
        ?string $storageDirectory = null,
        ?array $metadata = null,
    ): ProcurementDocument {
        if (! in_array($type, ProcurementDocument::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid procurement document type: {$type}");
        }

        $this->assertVendorInvoiceUniqueness($mrf, $type, $vendorId);

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $directory = $storageDirectory ?? ('procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id);
        $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFileName);
        $path = $directory . '/' . $fileName;

        Storage::disk($disk)->put($path, $binaryContent);
        $url = $this->fileUrl($path, $disk);

        return $this->createDocumentRecord($mrf, $type, $originalFileName, $path, $url, $user, $vendorId, $metadata);
    }

    public function storePreviewBinary(
        MRF $mrf,
        string $binaryContent,
        string $originalFileName,
        string $type,
        User $user,
        ?array $metadata = null,
    ): array {
        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $directory = 'procurement-documents/previews/' . date('Y/m') . '/' . $mrf->mrf_id;
        $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalFileName);
        $path = $directory . '/' . $fileName;

        Storage::disk($disk)->put($path, $binaryContent);
        $url = $this->fileUrl($path, $disk);

        return [
            'id' => null,
            'mrfId' => $mrf->mrf_id,
            'vendorId' => $this->resolveVendorId($mrf),
            'type' => $type,
            'fileName' => $originalFileName,
            'filePath' => $path,
            'fileUrl' => $url,
            'uploadedBy' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'uploadedAt' => now()->toIso8601String(),
            'version' => null,
            'isActive' => false,
            'preview' => true,
            'metadata' => $metadata,
            'file_name' => $originalFileName,
            'file_path' => $path,
            'file_url' => $url,
            'uploaded_by' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'uploaded_at' => now()->toIso8601String(),
            'is_active' => false,
        ];
    }

    public function registerExistingStorageFile(
        MRF $mrf,
        string $type,
        string $storagePath,
        string $fileUrl,
        string $fileName,
        User $user,
        ?int $vendorId = null,
    ): ProcurementDocument {
        if (! in_array($type, ProcurementDocument::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid procurement document type: {$type}");
        }

        $this->assertVendorInvoiceUniqueness($mrf, $type, $vendorId);

        return $this->createDocumentRecord($mrf, $type, $fileName, $storagePath, $fileUrl, $user, $vendorId);
    }

    public function resolveVendorId(MRF $mrf): ?int
    {
        if ($mrf->selected_vendor_id) {
            return (int) $mrf->selected_vendor_id;
        }

        return null;
    }

    public function hasActiveDocument(MRF $mrf, string $type, ?int $vendorId = null): bool
    {
        return $this->findActiveDocument($mrf, $type, $vendorId) !== null;
    }

    public function findActiveDocument(MRF $mrf, string $type, ?int $vendorId = null): ?ProcurementDocument
    {
        return ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->latest('uploaded_at')
            ->first();
    }

    /**
     * @param  list<string>  $types
     * @return list<string> Missing document types from the required set.
     */
    public function missingDocumentTypes(MRF $mrf, array $types, ?int $vendorId = null): array
    {
        $missing = [];

        foreach (array_unique($types) as $type) {
            if (! $this->hasActiveDocument($mrf, $type, $vendorId)) {
                $missing[] = $type;
            }
        }

        return $missing;
    }

    public function syncGrnLegacyFields(MRF $mrf, ProcurementDocument $document): void
    {
        $mrf->update([
            'grn_completed' => true,
            'grn_completed_at' => now(),
            'grn_completed_by' => $document->uploaded_by,
            'grn_url' => $document->file_url,
            'grn_share_url' => $document->file_url,
        ]);
    }

    public function storeUpload(
        MRF $mrf,
        UploadedFile $file,
        string $type,
        User $user,
        ?int $vendorId = null,
        ?string $storageDirectory = null,
    ): ProcurementDocument {
        if (! in_array($type, ProcurementDocument::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid procurement document type: {$type}");
        }

        $this->assertVendorInvoiceUniqueness($mrf, $type, $vendorId);

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $directory = $storageDirectory ?? ('procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id);
        $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->fileUrl($path, $disk);

        return $this->createDocumentRecord(
            $mrf,
            $type,
            $file->getClientOriginalName(),
            $path,
            $url,
            $user,
            $vendorId,
        );
    }

    public function registerFromLegacyUrl(
        MRF $mrf,
        string $type,
        ?string $url,
        ?string $filePath,
        ?string $fileName,
        ?User $user = null,
        ?int $vendorId = null,
    ): ?ProcurementDocument {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->exists()) {
            return null;
        }

        return ProcurementDocument::create([
            'mrf_id' => $mrf->id,
            'vendor_id' => $vendorId,
            'type' => $type,
            'file_name' => $fileName ?? basename(parse_url($url, PHP_URL_PATH) ?: 'document'),
            'file_path' => $filePath ?? $url,
            'file_url' => $url,
            'uploaded_by' => $user?->id,
            'uploaded_at' => now(),
            'version' => 1,
            'is_active' => true,
        ]);
    }

    public function transform(ProcurementDocument $document): array
    {
        return DocumentDisplayPayload::withCamelCaseAliases([
            'id' => $document->id,
            'mrfId' => $document->mrf_id,
            'vendorId' => $document->vendor_id,
            'type' => $document->type,
            'fileName' => $document->file_name,
            'filePath' => $document->file_path,
            'fileUrl' => $document->file_url,
            'uploadedBy' => $document->uploader ? [
                'id' => $document->uploader->id,
                'name' => $document->uploader->name,
            ] : null,
            'uploadedAt' => $document->uploaded_at?->toIso8601String(),
            'version' => $document->version,
            'isActive' => $document->is_active,
            'metadata' => $document->metadata,
        ]);
    }

    public function refreshFileUrl(string $filePath, ?string $disk = null): string
    {
        $disk ??= config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));

        return $this->fileUrl($filePath, $disk);
    }

    private function fileUrl(string $filePath, string $disk): string
    {
        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl($filePath, now()->addHours(168));
            } catch (\Exception $e) {
                Log::warning('S3 temporary URL generation failed for procurement document', [
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                ]);

                return Storage::disk($disk)->url($filePath);
            }
        }

        $url = Storage::disk($disk)->url($filePath);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }

    private function createDocumentRecord(
        MRF $mrf,
        string $type,
        string $fileName,
        string $path,
        string $url,
        User $user,
        ?int $vendorId = null,
        ?array $metadata = null,
    ): ProcurementDocument {
        $nextVersion = (int) ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', $type)
            ->max('version') + 1;

        ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', $type)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return ProcurementDocument::create([
            'mrf_id' => $mrf->id,
            'vendor_id' => $vendorId,
            'type' => $type,
            'file_name' => $fileName,
            'file_path' => $path,
            'file_url' => $url,
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
            'version' => max(1, $nextVersion),
            'is_active' => true,
            'metadata' => $metadata,
        ]);
    }

    private function assertVendorInvoiceUniqueness(MRF $mrf, string $type, ?int $vendorId): void
    {
        if ($type !== ProcurementDocument::TYPE_VENDOR_INVOICE) {
            return;
        }

        $existing = ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', ProcurementDocument::TYPE_VENDOR_INVOICE)
            ->where('is_active', true)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->exists();

        if ($existing) {
            throw new \RuntimeException('An active vendor invoice already exists for this MRF.');
        }
    }
}
