<?php

namespace App\Services;

use App\Mail\PORevisedForResignMail;
use App\Models\AuditLog;
use App\Models\MRF;
use App\Models\User;
use App\Notifications\SystemAnnouncementNotification;
use App\Support\DatabaseNotifications;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PurchaseOrderRevisionService
{
    public const EDITOR_ROLES = ['procurement_manager', 'procurement', 'admin'];

    public function __construct(
        private WorkflowStateService $workflowStateService,
        private PurchaseOrderService $purchaseOrders,
        private RefreshUnsignedPurchaseOrderPdfService $unsignedPdf,
    ) {
    }

    public static function userCanRevise(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $role = strtolower(trim((string) ($user->scmRole() ?? '')));

        return in_array($role, self::EDITOR_ROLES, true);
    }

    public function isPendingRevision(MRF $mrf): bool
    {
        $status = strtolower(trim((string) ($mrf->status ?? '')));
        $state = strtolower(trim((string) ($mrf->workflow_state ?? '')));

        return $status === WorkflowStateService::STATE_PENDING_REVISION
            || $state === WorkflowStateService::STATE_PENDING_REVISION;
    }

    public function isSignedAndLocked(MRF $mrf): bool
    {
        if ($this->isPendingRevision($mrf)) {
            return false;
        }

        // Any non-empty signed PDF URL locks the PO until unlock-for-edit,
        // even after workflow_state advances past po_signed (delivery/finance).
        return trim((string) ($mrf->signed_po_url ?? '')) !== '';
    }

    public function isDraftEditable(MRF $mrf): bool
    {
        if ($this->isPendingRevision($mrf)) {
            return true;
        }

        if ($this->isSignedAndLocked($mrf) || filled($mrf->signed_po_url)) {
            return false;
        }

        return $mrf->isPoDraft()
            || blank($mrf->unsigned_po_url)
            || blank($mrf->po_number);
    }

    /**
     * Unlock a signed PO so procurement can edit it.
     *
     * @return array<string, mixed>
     */
    public function unlockForEdit(MRF $mrf, User $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to unlock a signed purchase order.',
            ]);
        }

        if ($this->isPendingRevision($mrf)) {
            return $this->payload($mrf->fresh() ?? $mrf, 'Purchase order is already unlocked for revision');
        }

        if (! $this->isSignedAndLocked($mrf)) {
            throw ValidationException::withMessages([
                'status' => 'Only signed purchase orders can be unlocked for edit.',
            ]);
        }

        $mrf->loadMissing(['items', 'selectedVendor']);
        $snapshot = $this->snapshot($mrf);

        $mrf->fill([
            'revision_snapshot' => $snapshot,
            'unlocked_by' => $actor->id,
            'unlocked_at' => now(),
            'unlock_reason' => $reason,
            'signed_po_url' => null,
            'signed_po_share_url' => null,
            'po_signed_at' => null,
            'status' => WorkflowStateService::STATE_PENDING_REVISION,
            'workflow_state' => WorkflowStateService::STATE_PENDING_REVISION,
            'current_stage' => 'procurement',
        ]);
        $mrf->save();

        $this->writeAudit($actor, $mrf, 'po_unlocked_for_edit', $reason, [
            'previous_signed_po_url' => $snapshot['signed_po_url'] ?? null,
            'previous_unsigned_po_url' => $snapshot['unsigned_po_url'] ?? null,
            'previous_status' => $snapshot['status'] ?? null,
            'previous_workflow_state' => $snapshot['workflow_state'] ?? null,
        ]);

        return $this->payload($mrf->fresh() ?? $mrf, 'Purchase order unlocked for revision');
    }

    /**
     * Persist edited PO fields while in pending_revision.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function updatePendingRevision(MRF $mrf, array $validated): array
    {
        if (! $this->isPendingRevision($mrf)) {
            throw ValidationException::withMessages([
                'status' => 'Signed purchase orders can only be edited after they are unlocked for revision.',
            ]);
        }

        $this->purchaseOrders->updateDraft($mrf, $validated);

        return $this->payload($this->purchaseOrders->findForEdit($mrf->mrf_id) ?? $mrf->fresh() ?? $mrf, 'Purchase order updated');
    }

    /**
     * Diff current values against the unlock snapshot, regenerate the unsigned PDF,
     * route back to the SCD, and notify directors.
     *
     * @return array<string, mixed>
     */
    public function submitForResign(MRF $mrf, User $actor): array
    {
        if (! $this->isPendingRevision($mrf)) {
            throw ValidationException::withMessages([
                'status' => 'Only purchase orders in pending revision can be submitted for re-signing.',
            ]);
        }

        $mrf->loadMissing(['items', 'selectedVendor']);
        $previous = is_array($mrf->revision_snapshot) ? $mrf->revision_snapshot : $this->snapshot($mrf);
        $current = $this->snapshot($mrf);
        $changedFields = $this->diffSnapshots($previous, $current);

        $revisionNumber = (int) ($mrf->revision_number ?? 0) + 1;
        $history = is_array($mrf->revision_history) ? $mrf->revision_history : [];
        $revisedAt = now();

        $history[] = [
            'revision_number' => $revisionNumber,
            'previous_unsigned_po_url' => $previous['unsigned_po_url'] ?? null,
            'previous_signed_po_url' => $previous['signed_po_url'] ?? null,
            'changed_fields' => $changedFields,
            'editor_user_id' => $actor->id,
            'editor_name' => $actor->name,
            'timestamp' => $revisedAt->toIso8601String(),
            'unlock_reason' => $mrf->unlock_reason,
        ];

        $mrf->fill([
            'revision_number' => $revisionNumber,
            'po_version' => max((int) ($mrf->po_version ?? 1), $revisionNumber),
            'revision_history' => $history,
            'status' => WorkflowStateService::STATE_PENDING_SCD_SIGNATURE,
            'workflow_state' => WorkflowStateService::STATE_PENDING_SCD_SIGNATURE,
            'current_stage' => 'supply_chain',
            'signed_po_url' => null,
            'signed_po_share_url' => null,
            'po_signed_at' => null,
        ]);
        $mrf->save();

        $pdf = $this->unsignedPdf->refresh($mrf->fresh() ?? $mrf, $actor, ignoreStatus: true);
        if (! ($pdf['success'] ?? false)) {
            Log::warning('Revised PO unsigned PDF regeneration failed', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $pdf['error'] ?? 'unknown',
            ]);
        }

        $mrf->refresh();

        $notification = $this->notifyDirectors($mrf, $actor, $changedFields, $revisedAt->toIso8601String());

        $this->writeAudit($actor, $mrf, 'po_submitted_for_resign', $mrf->unlock_reason, [
            'revision_number' => $revisionNumber,
            'changed_fields' => $changedFields,
            'unsigned_po_url' => $mrf->unsigned_po_url,
        ]);

        return array_merge(
            $this->payload($this->purchaseOrders->findForEdit($mrf->mrf_id) ?? $mrf, 'Purchase order updated and sent for SCD re-signing'),
            [
                'revision_number' => $revisionNumber,
                'changed_fields' => $changedFields,
                'notifications' => $notification,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(MRF $mrf): array
    {
        $mrf->loadMissing(['items', 'selectedVendor']);

        $items = $mrf->items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_name' => (string) ($item->item_name ?? ''),
                'description' => (string) ($item->description ?? ''),
                'quantity' => (float) ($item->quantity ?? 0),
                'unit' => (string) ($item->unit ?? ''),
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : 0.0,
                'total_price' => $item->total_price !== null ? (float) $item->total_price : 0.0,
            ];
        })->values()->all();

        $itemTotal = collect($items)->sum(fn ($row) => (float) ($row['total_price'] ?? 0));
        $tax = (float) ($mrf->tax_amount ?? 0);
        $total = (float) ($mrf->po_value ?? $mrf->estimated_cost ?? ($itemTotal + $tax));

        return [
            'po_number' => $mrf->po_number,
            'status' => $mrf->status,
            'workflow_state' => $mrf->workflow_state,
            'unsigned_po_url' => $mrf->unsigned_po_url,
            'signed_po_url' => $mrf->signed_po_url,
            'total_value' => round($total, 2),
            'estimated_cost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
            'selected_vendor_id' => $mrf->selected_vendor_id,
            'supplier' => $mrf->selectedVendor?->name,
            'supplier_email' => $mrf->selectedVendor?->email,
            'expected_delivery_date' => $mrf->expected_delivery_date?->format('Y-m-d'),
            'payment_terms' => $mrf->po_payment_terms,
            'notes' => $mrf->remarks,
            'custom_terms' => $mrf->custom_terms,
            'ship_to_address' => $mrf->ship_to_address,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    public function diffSnapshots(array $before, array $after): array
    {
        $changes = [];

        $scalarFields = [
            'total_value' => 'Total value',
            'supplier' => 'Supplier',
            'expected_delivery_date' => 'Delivery date',
            'payment_terms' => 'Payment terms',
            'notes' => 'Notes',
            'custom_terms' => 'Custom terms',
            'ship_to_address' => 'Ship-to address',
            'currency' => 'Currency',
        ];

        foreach ($scalarFields as $key => $label) {
            $old = $this->stringify($before[$key] ?? null);
            $new = $this->stringify($after[$key] ?? null);
            if ($this->valuesDiffer($before[$key] ?? null, $after[$key] ?? null)) {
                $changes[] = $this->change($key, $label, $before[$key] ?? null, $after[$key] ?? null, $old, $new);
            }
        }

        $beforeItems = collect($before['items'] ?? []);
        $afterItems = collect($after['items'] ?? []);
        $max = max($beforeItems->count(), $afterItems->count());

        for ($i = 0; $i < $max; $i++) {
            $oldItem = $beforeItems->get($i);
            $newItem = $afterItems->get($i);
            $name = (string) ($newItem['item_name'] ?? $oldItem['item_name'] ?? ('Line '.($i + 1)));

            if ($oldItem === null && $newItem !== null) {
                $changes[] = $this->change(
                    'line_item_added_'.$i,
                    'Line item added: '.$name,
                    null,
                    $newItem,
                    '—',
                    $this->formatLineItem($newItem),
                );
                continue;
            }

            if ($oldItem !== null && $newItem === null) {
                $changes[] = $this->change(
                    'line_item_removed_'.$i,
                    'Line item removed: '.$name,
                    $oldItem,
                    null,
                    $this->formatLineItem($oldItem),
                    '—',
                );
                continue;
            }

            if ($this->valuesDiffer($oldItem['quantity'] ?? null, $newItem['quantity'] ?? null)) {
                $changes[] = $this->change(
                    'line_item_quantity_'.$i,
                    'Quantity — '.$name,
                    $oldItem['quantity'] ?? null,
                    $newItem['quantity'] ?? null,
                );
            }

            if ($this->valuesDiffer($oldItem['unit_price'] ?? null, $newItem['unit_price'] ?? null)) {
                $changes[] = $this->change(
                    'line_item_unit_price_'.$i,
                    'Unit price — '.$name,
                    $oldItem['unit_price'] ?? null,
                    $newItem['unit_price'] ?? null,
                );
            }

            if ($this->valuesDiffer($oldItem['item_name'] ?? null, $newItem['item_name'] ?? null)) {
                $changes[] = $this->change(
                    'line_item_name_'.$i,
                    'Line item name',
                    $oldItem['item_name'] ?? null,
                    $newItem['item_name'] ?? null,
                );
            }
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $changedFields
     * @return array{emailed: int, notified: int}
     */
    public function notifyDirectors(MRF $mrf, User $editor, array $changedFields, string $revisedAt): array
    {
        $directors = User::query()
            ->whereNotNull('email')
            ->where(function ($query) {
                $query->whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain']);
            })
            ->get();

        $poNumber = (string) ($mrf->po_number ?: $mrf->mrf_id);
        $signingUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/')
            .'/supply-chain?po='.urlencode((string) $mrf->mrf_id);
        $summaryLines = collect($changedFields)
            ->map(function (array $change) {
                $label = $change['label'] ?? $change['field'];
                $before = $change['before_display'] ?? $this->stringify($change['before'] ?? null);
                $after = $change['after_display'] ?? $this->stringify($change['after'] ?? null);

                return $label.': '.$before.' → '.$after;
            })
            ->values()
            ->all();

        $message = sprintf(
            'Purchase Order %s has been revised by %s and requires a new signature. Changes: %s',
            $poNumber,
            $editor->name,
            $summaryLines === [] ? 'see PO detail for the revision summary.' : implode('; ', $summaryLines)
        );

        $emailPayload = [
            'po_number' => $poNumber,
            'editor_name' => $editor->name,
            'revised_at' => $revisedAt,
            'unlock_reason' => $mrf->unlock_reason,
            'change_summary' => $changedFields,
            'signing_url' => $signingUrl,
        ];

        $emailed = 0;
        $notified = 0;

        foreach ($directors as $director) {
            try {
                DatabaseNotifications::send($director, new SystemAnnouncementNotification(
                    'Revised PO requires a new signature',
                    $message,
                    $signingUrl,
                    'high',
                    [
                        'type' => 'po_revised_for_resign',
                        'event' => 'po_revised_for_resign',
                        'po_number' => $poNumber,
                        'mrf_id' => $mrf->mrf_id,
                        'revision_number' => (int) ($mrf->revision_number ?? 0),
                        'editor_name' => $editor->name,
                        'revised_at' => $revisedAt,
                        'changed_fields' => $changedFields,
                        'action_url' => $signingUrl,
                    ],
                ));
                $notified++;
            } catch (\Throwable $e) {
                Log::warning('Revised PO in-app notification failed', [
                    'user_id' => $director->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $email = (string) $director->email;
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                $mail = Mail::to($email);
                if (config('queue.default') !== 'sync') {
                    $mail->queue(new PORevisedForResignMail($mrf, $emailPayload));
                } else {
                    $mail->send(new PORevisedForResignMail($mrf, $emailPayload));
                }
                $emailed++;
            } catch (\Throwable $e) {
                Log::warning('Revised PO email notification failed', [
                    'user_id' => $director->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['emailed' => $emailed, 'notified' => $notified];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writeAudit(User $actor, MRF $mrf, string $action, ?string $reason, array $extra = []): void
    {
        try {
            AuditLog::create([
                'action' => $action,
                'description' => sprintf(
                    '%s unlocked/revised PO %s%s',
                    $actor->name,
                    $mrf->po_number ?: $mrf->mrf_id,
                    $reason ? ' — '.$reason : ''
                ),
                'actor_id' => $actor->id,
                'actor_type' => $actor::class,
                'entity_type' => 'purchase_order',
                'entity_id' => (string) ($mrf->po_number ?: $mrf->mrf_id),
                'payload' => array_merge([
                    'mrf_id' => $mrf->mrf_id,
                    'po_number' => $mrf->po_number,
                    'actor_id' => $actor->id,
                    'actor_name' => $actor->name,
                    'reason' => $reason,
                    'timestamp' => now()->toIso8601String(),
                ], $extra),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PO revision audit log failed', [
                'action' => $action,
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        $history = is_array($mrf->approval_history) ? $mrf->approval_history : [];
        $history[] = [
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ];
        try {
            $mrf->forceFill(['approval_history' => $history])->saveQuietly();
        } catch (\Throwable) {
            // Non-fatal; the dedicated audit log is the source of truth.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MRF $mrf, string $message): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $this->purchaseOrders->mapEditPayload($mrf),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function change(
        string $field,
        string $label,
        mixed $before,
        mixed $after,
        ?string $beforeDisplay = null,
        ?string $afterDisplay = null,
    ): array {
        return [
            'field' => $field,
            'label' => $label,
            'before' => $before,
            'after' => $after,
            'before_display' => $beforeDisplay ?? $this->stringify($before),
            'after_display' => $afterDisplay ?? $this->stringify($after),
        ];
    }

    private function valuesDiffer(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) > 0.009;
        }

        return $this->stringify($left) !== $this->stringify($right);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return $this->formatLineItem($value);
        }
        if (is_numeric($value)) {
            return (string) (floor((float) $value) == (float) $value
                ? (0 + $value)
                : number_format((float) $value, 2, '.', ''));
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatLineItem(array $item): string
    {
        $name = (string) ($item['item_name'] ?? 'Item');
        $qty = $item['quantity'] ?? 0;
        $price = $item['unit_price'] ?? 0;

        return sprintf('%s (qty %s @ %s)', $name, $qty, $price);
    }
}
