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
     * @return array{inventory: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginateInventory(?string $search = null, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = $this->baseQuery($search);
        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $items = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (MRF $mrf) => $this->mapMrf($mrf))
            ->values()
            ->all();

        $from = $total === 0 ? null : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? null : min($total, $page * $perPage);

        return [
            'inventory' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInventory(?string $search = null, int $limit = 100): array
    {
        return $this->baseQuery($search)
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->map(fn (MRF $mrf) => $this->mapMrf($mrf))
            ->values()
            ->all();
    }

    private function baseQuery(?string $search = null)
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
            ->orderByDesc('updated_at');

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

        return $query;
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

        $lineItems = $this->mapLineItems($mrf);

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
            // Procurement context for inventory detail sheet
            'mrf_id' => $mrf->mrf_id,
            'po_number' => $poNumber,
            'title' => $mrf->title,
            'vendor_name' => $mrf->selectedVendor?->name,
            'force_closed' => $mrf->force_closed_at !== null,
            'force_close_reason' => $mrf->force_close_reason,
            'workflow_state' => $mrf->workflow_state,
            'status' => $mrf->status,
            'line_items' => $lineItems,
            'line_item_count' => count($lineItems),
            'source' => 'completed_procurement',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapLineItems(MRF $mrf): array
    {
        if (! $mrf->relationLoaded('items') || $mrf->items->isEmpty()) {
            $fallbackName = trim((string) ($mrf->title ?? ''));
            $fallbackQty = $this->resolveQuantity($mrf);
            if ($fallbackName === '' && $fallbackQty <= 0) {
                return [];
            }

            return [[
                'id' => null,
                'item_name' => $fallbackName !== '' ? $fallbackName : 'Line item',
                'description' => $mrf->justification ?? $mrf->remarks ?? null,
                'quantity' => $fallbackQty > 0 ? $fallbackQty : 1,
                'unit' => 'EA',
                'unit_price' => $mrf->po_value !== null ? (float) $mrf->po_value : null,
                'total_price' => $mrf->po_value !== null ? (float) $mrf->po_value : null,
            ]];
        }

        return $mrf->items->map(static function ($item) {
            return [
                'id' => $item->id,
                'item_name' => $item->item_name ?: ($item->description ?: 'Item'),
                'description' => $item->description,
                'quantity' => (float) ($item->quantity ?? 0),
                'unit' => $item->unit ?: 'EA',
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'total_price' => $item->total_price !== null ? (float) $item->total_price : null,
            ];
        })->values()->all();
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
