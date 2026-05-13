<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Builds public URLs for user signature images.
 * Local/public disks use an API proxy route so SPA fetch() gets CORS (api/*).
 * S3 uses a time-limited URL (configure bucket CORS for the frontend origin).
 */
final class SignatureUrls
{
    public static function forUser(?User $user): ?string
    {
        if (!$user || empty($user->signature_image_path)) {
            return null;
        }

        return self::forPath($user->signature_image_path, $user->id);
    }

    public static function forPath(?string $path, int $userId): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));

        try {
            if ($disk === 's3') {
                return Storage::disk($disk)->temporaryUrl($path, now()->addDays(7));
            }
        } catch (\Throwable) {
            return null;
        }

        return URL::to('/api/users/'.$userId.'/signature-file');
    }
}
