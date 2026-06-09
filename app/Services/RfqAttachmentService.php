<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RfqAttachmentService
{
    public function __construct(
        private QuotationAttachmentService $quotationAttachmentService,
    ) {
    }

    /**
     * @param  UploadedFile[]  $files
     * @return list<array{id: string, filename: string, url: string, file_path: string, disk: string}>
     */
    public function storeUploadedAttachments(array $files, string $rfqId): array
    {
        $disk = $this->quotationAttachmentService->getStorageDisk();
        $year = date('Y');
        $basePath = "rfq_attachments/{$year}/{$rfqId}";

        $out = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $fileName = $safeBaseName.'_'.Str::random(8).'_'.time().($extension ? ".{$extension}" : '');
            $filePath = $file->storeAs($basePath, $fileName, $disk);
            $url = $this->quotationAttachmentService->getSignedUrl($filePath, $disk);

            $out[] = [
                'id' => (string) Str::uuid(),
                'filename' => $originalName,
                'url' => $url,
                'file_path' => $filePath,
                'disk' => $disk,
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $documents
     * @return list<array<string, mixed>>
     */
    public function hydrateSupportingDocuments($documents): array
    {
        if ($documents === null || $documents === '' || $documents === []) {
            return [];
        }

        if (! is_array($documents)) {
            return [];
        }

        $isAssoc = array_keys($documents) !== range(0, count($documents) - 1);
        if ($isAssoc) {
            $documents = [$documents];
        }

        $out = [];

        foreach ($documents as $document) {
            if ($document === null || $document === '') {
                continue;
            }

            if (is_string($document)) {
                $out[] = [
                    'id' => null,
                    'filename' => basename(parse_url($document, PHP_URL_PATH) ?: 'document'),
                    'url' => $document,
                ];

                continue;
            }

            if (! is_array($document)) {
                continue;
            }

            $filePath = $document['file_path'] ?? $document['path'] ?? null;
            $disk = $document['disk'] ?? null;
            $url = $document['url'] ?? $document['file_url'] ?? $document['shareUrl'] ?? null;

            if ($filePath) {
                try {
                    $url = $this->quotationAttachmentService->getSignedUrl($filePath, $disk);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to refresh RFQ supporting document URL', [
                        'file_path' => $filePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $out[] = [
                'id' => $document['id'] ?? null,
                'filename' => $document['filename'] ?? $document['file_name'] ?? $document['name'] ?? 'document',
                'url' => $url,
                'type' => $document['type'] ?? null,
                'uploadedAt' => $document['uploaded_at'] ?? $document['uploadedAt'] ?? null,
            ];
        }

        return array_values($out);
    }
}
