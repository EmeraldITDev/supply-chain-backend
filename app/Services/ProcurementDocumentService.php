<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
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

        if ($type === ProcurementDocument::TYPE_VENDOR_INVOICE) {
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

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $directory = $storageDirectory ?? ('procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id);
        $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->fileUrl($path, $disk);

        return ProcurementDocument::create([
            'mrf_id' => $mrf->id,
            'vendor_id' => $vendorId,
            'type' => $type,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_url' => $url,
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
            'version' => max(1, $nextVersion),
            'is_active' => true,
        ]);
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
        return [
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
        ];
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
}
