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
        mixed $payloadOrRequest = [],
        ?Request $request = null
    ): void {
        // Handle the case where payload or request is passed in position 6
        $payload = [];
        $finalRequest = $request;
        
        if ($payloadOrRequest instanceof Request) {
            $finalRequest = $payloadOrRequest;
        } elseif (is_array($payloadOrRequest)) {
            $payload = $payloadOrRequest;
        }
        
        // If description is an array, convert to string for storage
        $finalDescription = is_array($description) 
            ? json_encode($description) 
            : (string) $description;

        AuditLog::create([
            'action' => $action,
            'description' => $finalDescription ?: "Activity logged: " . $action, 
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actor::class : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'ip_address' => $finalRequest?->ip(),
            'user_agent' => $finalRequest?->userAgent(),
        ]);
    }
}
