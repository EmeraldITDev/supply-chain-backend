<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class UserSignatureFileController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Stream the user's signature image with API CORS (for SPA fetch → data URL).
     */
    public function show(Request $request, User $user): Response
    {
        try {
            $actor = $request->user();
            if (!$actor) {
                return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
            }

            $isAdmin = $this->permissionService->canManageUsers($actor) || $actor->scmRole() === 'admin';
            if (!$isAdmin && $actor->id !== $user->id) {
                return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
            }

            if (empty($user->signature_image_path)) {
                return response()->json(['success' => false, 'error' => 'No signature on file'], 404);
            }

            $diskName = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
            $path = $this->normalizeSignatureStoragePath((string) $user->signature_image_path);

            if ($path === '') {
                return response()->json(['success' => false, 'error' => 'Invalid signature path'], 404);
            }

            $diskCandidates = array_values(array_unique(array_filter([
                $diskName,
                $diskName !== 'public' ? 'public' : null,
                'local',
            ])));

            $lastError = null;
            foreach ($diskCandidates as $tryDisk) {
                if (! config("filesystems.disks.{$tryDisk}")) {
                    continue;
                }
                try {
                    $disk = Storage::disk($tryDisk);
                    $exists = $disk->exists($path) || (method_exists($disk, 'fileExists') && $disk->fileExists($path));
                    if (! $exists) {
                        continue;
                    }

                    $contents = $disk->get($path);
                    if (! is_string($contents) || $contents === '') {
                        $lastError = 'empty_read';

                        continue;
                    }

                    $mime = self::guessMime($path);

                    return response($contents, 200, [
                        'Content-Type' => $mime,
                        'Cache-Control' => 'private, max-age=3600',
                    ]);
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    report($e);
                }
            }

            \Log::warning('Signature file not readable on any disk candidate', [
                'user_id' => $user->id,
                'path' => $path,
                'tried_disks' => $diskCandidates,
                'last_error' => $lastError,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Signature file missing or unreadable. Try re-uploading from Settings.',
            ], 404);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'Could not load signature file.',
            ], 500);
        }
    }

    /**
     * Strip accidental URL or /storage prefix so Flysystem paths stay relative to the disk root.
     */
    private function normalizeSignatureStoragePath(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            $path = (string) (parse_url($stored, PHP_URL_PATH) ?: '');
            $stored = $path;
        }

        $stored = ltrim($stored, '/');
        if (str_starts_with($stored, 'storage/')) {
            $stored = substr($stored, strlen('storage/'));
        }

        return ltrim($stored, '/');
    }

    private static function guessMime(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
