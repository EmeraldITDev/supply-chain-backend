<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Additive SCM audit writer for MRF / PO / Vendor changes.
 * Does not replace MRFApprovalHistory or Activity feeds.
 */
class ScmAuditService
{
    public function record(
        string $action,
        string $entityType,
        string|int|null $entityId,
        ?User $actor,
        ?string $description = null,
        array $payload = [],
        ?Request $request = null,
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'action' => $action,
                'description' => $description,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? User::class : null,
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? (string) $entityId : null,
                'payload' => $payload === [] ? null : $payload,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SCM audit write failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChange(
        string $action,
        Model $model,
        ?User $actor,
        array $before,
        array $after,
        ?string $description = null,
        ?Request $request = null,
    ): ?AuditLog {
        $changed = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue != $newValue) {
                $changed[$key] = [
                    'previous' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        if ($changed === []) {
            return null;
        }

        $entityId = $model->getAttribute('mrf_id')
            ?? $model->getAttribute('vendor_id')
            ?? $model->getAttribute('rfq_id')
            ?? $model->getKey();

        return $this->record(
            $action,
            class_basename($model),
            $entityId,
            $actor,
            $description,
            ['changes' => $changed],
            $request,
        );
    }
}
