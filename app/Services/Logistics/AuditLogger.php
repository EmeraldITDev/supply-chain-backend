<?php

namespace App\Services\Logistics;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(
        string $action,
        ?User $actor,
        ?string $entityType,
        ?string $entityId,
        mixed $description,
        mixed $payload = [],
        ?Request $request = null
    ): void {
        // Some callers historically pass the Request object as the 6th arg
        // (payload) rather than the 7th — swallow that gracefully so the
        // audit log never blocks the parent request.
        if ($payload instanceof Request && $request === null) {
            $request = $payload;
            $payload = [];
        }

        $finalPayload = is_array($payload) ? $payload : (is_object($payload) ? json_decode(json_encode($payload), true) ?? [] : []);
        $finalDescription = is_array($description) || is_object($description)
            ? json_encode($description)
            : (string) $description;

        try {
            AuditLog::create([
                'action' => $action,
                'description' => $finalDescription,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? $actor::class : null,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => $finalPayload,
                'ip_address' => $request?->ip() ?? request()->ip(),
                'user_agent' => $request?->userAgent() ?? request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // Audit failures must never bubble up and tank the parent request.
            \Log::warning('AuditLogger failed to persist entry', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
