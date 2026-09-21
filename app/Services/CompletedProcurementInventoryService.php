<?php

namespace App\Services;

use App\Models\MRF;
use App\Services\WorkflowStateService;

/**
 * Maps closed / force-closed purchase orders (MRF-backed) into warehouse inventory rows
 * so completed procurement appears under Warehouse → Inventory.
 */
class CompletedProcurementInventoryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listInventory(?string $search = null, int $limit = 100): array
    {
        $query = MRF::query()
            ->with(['selectedVendor:id,vendor_id,name', 'items'])
            ->where(function ($q) {
                $q->where('workflow_state', WorkflowStateService::STATE_CLOSED)
                    ->orWhereRaw('LOWER(COALESCE(status, \'\')) = ?', ['completed'])
                    ->orWhereNotNull('force_closed_at');
            })
            ->where(function ($q) {
                $q->whereNotNull('po_number')
                    ->where('po_number', '!=', '')
                    ->orWhere(function ($signed) {
                        $signed->whereNotNull('signed_po_url')->where('signed_po_url', '!=', '');
                    });
            })
            ->orderByDesc('force_closed_at')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(500, $limit)));

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function ($q) use ($term) {
                $q->where('po_number', 'ilike', $term)
                    ->orWhere('mrf_id', 'ilike', $term)
                    ->orWhere('formatted_id', 'ilike', $term)
                    ->orWhere('title', 'ilike', $term)
                    ->orWhere('linked_po_id', 'ilike', $term);
            });
        }

        return $query->get()->map(fn (MRF $mrf) => $this->mapMrf($mrf))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMrf(MRF $mrf): array
    {
        $qty = $this->resolveQuantity($mrf);
        $unitCost = $mrf->po_value !== null
            ? (float) $mrf->po_value
            : ($mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null);
        $poNumber = $mrf->effectivePoNumber() ?: ($mrf->po_number ?: $mrf->mrf_id);
        $closedAt = $mrf->force_closed_at ?? $mrf->updated_at;

        return [
            'id' => 'po-'.$mrf->id,
            'item_id' => (string) $mrf->id,
            'sku' => (string) $poNumber,
            'description' => trim(($mrf->title ?: 'Completed procurement').' · MRF '.$mrf->mrf_id),
            'category' => $mrf->category,
            'uom' => 'EA',
            'location_id' => null,
            'location_path' => $mrf->force_closed_at
                ? 'Completed procurement / Force closed'
                : 'Completed procurement / Closed',
            'bin_code' => null,
            'qty_on_hand' => $qty,
            'qty_reserved' => 0,
            'qty_available' => $qty,
            'reorder_level' => null,
            'safety_stock_level' => null,
            'batch_number' => $mrf->mrf_id,
            'lot_number' => $mrf->formatted_id,
            'serial_number' => null,
            'expiry_date' => null,
            'manufacturing_date' => null,
            'unit_cost' => $unitCost,
            'total_value' => $unitCost,
            'valuation_method' => null,
            'is_quarantined' => false,
            'last_movement_at' => optional($closedAt)?->toIso8601String(),
            // Extra procurement context (ignored by strict UIs; useful for debugging)
            'mrf_id' => $mrf->mrf_id,
            'po_number' => $poNumber,
            'vendor_name' => $mrf->selectedVendor?->name,
            'force_closed' => $mrf->force_closed_at !== null,
            'workflow_state' => $mrf->workflow_state,
            'status' => $mrf->status,
        ];
    }

    private function resolveQuantity(MRF $mrf): float
    {
        if ($mrf->relationLoaded('items') && $mrf->items->isNotEmpty()) {
            $sum = $mrf->items->sum(function ($item) {
                return (float) ($item->quantity ?? 0);
            });
            if ($sum > 0) {
                return $sum;
            }
        }

        $raw = trim((string) ($mrf->quantity ?? ''));
        if ($raw !== '' && is_numeric($raw)) {
            return (float) $raw;
        }

        return 1.0;
    }
}
