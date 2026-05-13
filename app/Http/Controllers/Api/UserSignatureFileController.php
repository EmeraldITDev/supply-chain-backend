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
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $isAdmin = $this->permissionService->canManageUsers($actor) || $actor->role === 'admin';
        if (!$isAdmin && $actor->id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        if (empty($user->signature_image_path)) {
            return response()->json(['success' => false, 'error' => 'No signature on file'], 404);
        }

        $diskName = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
        $disk = Storage::disk($diskName);
        $path = $user->signature_image_path;

        if (!$disk->exists($path)) {
            return response()->json(['success' => false, 'error' => 'Signature file missing'], 404);
        }

        $mime = self::guessMime($path);

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
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
