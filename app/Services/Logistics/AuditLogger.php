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
        ?string $description,
        array $payload = [],
        ?Request $request = null
    ): void {
        AuditLog::create([
            'action' => $action,
            // 2. Use the null coalescing operator to provide a fallback string
            'description' => $description ?? "Action: {$action} performed", 
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actor::class : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
