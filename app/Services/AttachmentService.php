<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\MRF;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AttachmentService
{
    public const COLLECTION_SUPPORTING_DOCUMENTS = 'supporting_documents';

    public const MAX_FILE_SIZE_KB = 10240;

    private const ALLOWED_EXTENSIONS = [
        'pdf',
        'xls',
        'xlsx',
        'csv',
        'png',
        'jpg',
        'jpeg',
        'webp',
    ];

    /**
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        $mimes = implode(',', self::ALLOWED_EXTENSIONS);

        return [
            'attachment' => 'nullable|file|mimes:'.$mimes.'|max:'.self::MAX_FILE_SIZE_KB,
            'attachments' => 'nullable',
            'attachments.*' => 'file|mimes:'.$mimes.'|max:'.self::MAX_FILE_SIZE_KB,
            'documents' => 'nullable',
            'documents.*' => 'file|mimes:'.$mimes.'|max:'.self::MAX_FILE_SIZE_KB,
            'invoice' => 'nullable|file|mimes:'.$mimes.'|max:'.self::MAX_FILE_SIZE_KB,
        ];
    }

    /**
     * Accept legacy single-file fields plus new multi-file arrays.
     *
     * @param  list<string>  $fields
     * @return list<UploadedFile>
     */
    public function filesFromRequest(Request $request, array $fields): array
    {
        $files = [];

        foreach ($fields as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $this->appendUploadedFiles($files, $request->file($field));
        }

        return $files;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeMany(
        Model $attachable,
        array $files,
        User $user,
        string $collection = self::COLLECTION_SUPPORTING_DOCUMENTS,
        ?string $directory = null,
    ): Collection {
        return collect($files)
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->map(fn (UploadedFile $file): Attachment => $this->store($attachable, $file, $user, $collection, $directory))
            ->values();
    }

    public function store(
        Model $attachable,
        UploadedFile $file,
        User $user,
        string $collection = self::COLLECTION_SUPPORTING_DOCUMENTS,
        ?string $directory = null,
    ): Attachment {
        $this->assertFileAllowed($file);

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
        $directory ??= $this->defaultDirectory($attachable);
        $fileName = $this->storedFileName($file);
        $path = $file->storeAs($directory, $fileName, $disk);

        return Attachment::create([
            'attachable_type' => $attachable::class,
            'attachable_id' => $attachable->getKey(),
            'collection' => $collection,
            'disk' => $disk,
            'file_path' => $path,
            'file_name' => $fileName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloadFor(Model $attachable, bool $includeLegacy = true): array
    {
        $attachments = $attachable->relationLoaded('attachments')
            ? $attachable->attachments
            : $attachable->attachments()
                ->with('uploader:id,name,email')
                ->orderByDesc('created_at')
                ->get();

        $payload = $attachments
            ->map(fn (Attachment $attachment): array => $this->transform($attachment))
            ->values()
            ->all();

        if ($payload === [] && $includeLegacy && $attachable instanceof MRF) {
            $legacy = $this->legacyMrfAttachment($attachable);
            if ($legacy !== null) {
                $payload[] = $legacy;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(Attachment $attachment): array
    {
        $url = $this->fileUrl($attachment->file_path, $attachment->disk);

        return [
            'id' => $attachment->id,
            'collection' => $attachment->collection,
            'fileName' => $attachment->original_name,
            'file_name' => $attachment->original_name,
            'storedName' => $attachment->file_name,
            'stored_name' => $attachment->file_name,
            'mimeType' => $attachment->mime_type,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'sizeBytes' => $attachment->size,
            'size_bytes' => $attachment->size,
            'disk' => $attachment->disk,
            'path' => $attachment->file_path,
            'url' => $url,
            'downloadUrl' => $url,
            'download_url' => $url,
            'uploadedBy' => $attachment->uploader ? [
                'id' => $attachment->uploader->id,
                'name' => $attachment->uploader->name,
                'email' => $attachment->uploader->email,
            ] : null,
            'uploaded_by' => $attachment->uploaded_by,
            'createdAt' => $attachment->created_at?->toIso8601String(),
            'created_at' => $attachment->created_at?->toIso8601String(),
            'metadata' => $attachment->metadata,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyMrfAttachment(MRF $mrf): ?array
    {
        if (! filled($mrf->attachment_url)) {
            return null;
        }

        return [
            'id' => null,
            'collection' => self::COLLECTION_SUPPORTING_DOCUMENTS,
            'fileName' => $mrf->attachment_name ?: basename((string) parse_url($mrf->attachment_url, PHP_URL_PATH)),
            'file_name' => $mrf->attachment_name ?: basename((string) parse_url($mrf->attachment_url, PHP_URL_PATH)),
            'storedName' => null,
            'stored_name' => null,
            'mimeType' => null,
            'mime_type' => null,
            'size' => null,
            'sizeBytes' => null,
            'size_bytes' => null,
            'disk' => null,
            'path' => $mrf->attachment_url,
            'url' => $mrf->attachment_share_url ?: $mrf->attachment_url,
            'downloadUrl' => $mrf->attachment_share_url ?: $mrf->attachment_url,
            'download_url' => $mrf->attachment_share_url ?: $mrf->attachment_url,
            'uploadedBy' => null,
            'uploaded_by' => null,
            'createdAt' => null,
            'created_at' => null,
            'metadata' => ['legacy' => true],
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function appendUploadedFiles(array &$files, mixed $value): void
    {
        if ($value instanceof UploadedFile) {
            $files[] = $value;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->appendUploadedFiles($files, $item);
        }
    }

    private function assertFileAllowed(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported attachment file type.');
        }

        if ($file->getSize() !== false && $file->getSize() > self::MAX_FILE_SIZE_KB * 1024) {
            throw new \InvalidArgumentException('Attachment exceeds the maximum allowed size.');
        }
    }

    private function defaultDirectory(Model $attachable): string
    {
        $prefix = match ($attachable::class) {
            \App\Models\MRF::class => 'mrfs',
            \App\Models\SRF::class => 'srfs',
            default => 'attachments',
        };

        $identifier = $attachable->getAttribute('mrf_id')
            ?: $attachable->getAttribute('srf_id')
            ?: $attachable->getKey();

        return $prefix.'/'.date('Y/m').'/'.$identifier.'/attachments';
    }

    private function storedFileName(UploadedFile $file): string
    {
        $original = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'attachment';
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $base = pathinfo($original, PATHINFO_FILENAME) ?: 'attachment';

        return now()->format('YmdHis').'_'.bin2hex(random_bytes(4)).'_'.$base.($extension ? '.'.$extension : '');
    }

    private function fileUrl(string $filePath, ?string $disk): string
    {
        $disk ??= config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));

        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl($filePath, now()->addHours(168));
            } catch (\Exception $e) {
                Log::warning('S3 temporary URL generation failed for attachment', [
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
