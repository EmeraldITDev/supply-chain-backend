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
        mixed $description, // Changed from ?string to mixed
        mixed $payload = [],
        ?Request $request = null
    ): void {
        // Safety check: if payload is actually the Request object (by mistake), 
        // swap it or set to empty array to prevent crash.
        $finalPayload = is_array($payload) ? $payload : [];
        // If an array or object is passed as description, convert it to a string
        $finalDescription = is_array($description) 
            ? json_encode($description) 
            : (string) $description;

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
    }
}
