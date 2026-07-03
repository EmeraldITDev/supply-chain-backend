<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QuotationAttachmentService
{
    public const DEFAULT_SIGNED_URL_DAYS = 7;

    public function getStorageDisk(): string
    {
        $disk = config('filesystems.quotation_attachments_disk', env('QUOTATION_ATTACHMENTS_DISK', 's3'));

        if ($disk === 's3') {
            if (!class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
                \Log::warning('S3 disk requested for quotation attachments but league/flysystem-aws-s3-v3 not installed. Falling back to public disk.');
                return 'public';
            }

            $s3Config = config('filesystems.disks.s3');
            if (empty($s3Config) || empty($s3Config['key']) || empty($s3Config['secret']) || empty($s3Config['bucket'])) {
                \Log::warning('S3 disk requested for quotation attachments but AWS config incomplete. Falling back to public disk.');
                return 'public';
            }

            try {
                Storage::disk('s3');
            } catch (\Exception $e) {
                \Log::warning('S3 disk requested for quotation attachments but could not resolve disk. Falling back to public disk.', [
                    'error' => $e->getMessage(),
                ]);
                return 'public';
            }
        }

        return $disk;
    }

    /**
     * Store uploaded quotation attachments and return DB-safe metadata.
     *
     * We intentionally store the storage path (key) and metadata, not a signed URL.
     * Signed URLs are generated on-demand so they can be refreshed after expiry.
     *
     * @param UploadedFile[] $files
     * @param array{rfq_id?:string, quotation_id?:string, vendor_name?:string, vendor_id?:string} $context
     * @return array<int, array<string, mixed>>
     */
    public function storeUploadedAttachments(array $files, array $context = []): array
    {
        $disk = $this->getStorageDisk();
        $year = date('Y');
        $rfqId = $context['rfq_id'] ?? 'unknown-rfq';
        $vendorSlug = Str::slug((string) ($context['vendor_name'] ?? $context['vendor_id'] ?? 'vendor'));
        $basePath = "quotation_attachments/{$year}/{$rfqId}/{$vendorSlug}";

        $out = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $safeBaseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $fileName = $safeBaseName . '_' . Str::random(8) . '_' . time() . ($extension ? ".{$extension}" : '');

            $filePath = $file->storeAs($basePath, $fileName, $disk);

            $out[] = [
                'disk' => $disk,
                'file_path' => $filePath,
                'file_name' => $originalName,
                'file_size' => $file->getSize(),
                'file_type' => $file->getMimeType(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        return $out;
    }

    public function getSignedUrl(string $filePath, ?string $disk = null, int $days = self::DEFAULT_SIGNED_URL_DAYS): string
    {
        $disk = $disk ?: $this->getStorageDisk();

        if ($disk === 's3') {
            return Storage::disk($disk)->temporaryUrl($filePath, now()->addDays($days));
        }

        return Storage::disk($disk)->url($filePath);
    }

    /**
     * Hydrate stored attachments to always include a fresh URL.
     * Accepts legacy formats (string URLs) and modern metadata arrays.
     *
     * @param mixed $attachments
     * @return array<int, mixed>
     */
    public function hydrateAttachments($attachments, bool $signUrls = true): array
    {
        if ($attachments === null || $attachments === '' || $attachments === []) {
            return [];
        }

        if (is_string($attachments)) {
            return [$attachments];
        }

        if (!is_array($attachments)) {
            return [];
        }

        // Single associative attachment object
        $isAssoc = array_keys($attachments) !== range(0, count($attachments) - 1);
        if ($isAssoc) {
            $attachments = [$attachments];
        }

        $out = [];
        foreach ($attachments as $a) {
            if ($a === null || $a === '') {
                continue;
            }

            // Legacy: raw URL string
            if (is_string($a)) {
                $out[] = $a;
                continue;
            }

            if (!is_array($a)) {
                continue;
            }

            $filePath = $a['file_path'] ?? $a['path'] ?? null;
            $disk = $a['disk'] ?? null;

            if ($filePath && $signUrls) {
                try {
                    $freshUrl = $this->getSignedUrl($filePath, $disk, self::DEFAULT_SIGNED_URL_DAYS);
                    $a['url'] = $freshUrl;
                    $a['file_url'] = $freshUrl;
                    $a['file_share_url'] = $freshUrl;
                } catch (\Exception $e) {
                    // Keep any previously stored URL fields as fallback
                    \Log::warning('Failed to generate signed URL for quotation attachment', [
                        'file_path' => $filePath,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $out[] = $a;
        }

        return array_values($out);
    }
}

