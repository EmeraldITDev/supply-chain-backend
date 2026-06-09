<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\PriceComparison;
use App\Models\Vendor;
use Illuminate\Support\Collection;

class PriceComparisonPoLineService
{
    /**
     * Stable supplier identity for grouping comparison rows on a PO.
     */
    public function supplierKey(PriceComparison $row): string
    {
        if ($row->vendor_id) {
            return 'vid:'.$row->vendor_id;
        }

        $row->loadMissing('vendor');
        $name = trim(strtolower((string) ($row->vendor?->name ?? '')));

        return $name !== '' ? 'manual:'.$name : 'unknown';
    }

    /**
     * All rows for the supplier marked is_selected, preserving save order.
     */
    public function selectedSupplierRows(MRF $mrf): Collection
    {
        $rows = $mrf->relationLoaded('priceComparisons')
            ? $mrf->priceComparisons->sortBy('id')->values()
            : $mrf->priceComparisons()->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $selected = $rows->firstWhere('is_selected', true);
        if (! $selected) {
            return collect();
        }

        $key = $this->supplierKey($selected);

        return $rows
            ->filter(fn (PriceComparison $row) => $this->supplierKey($row) === $key)
            ->values();
    }

    public function resolveVendorFromRows(Collection $rows): ?Vendor
    {
        $first = $rows->first();
        if (! $first) {
            return null;
        }

        if ($first->relationLoaded('vendor') && $first->vendor) {
            return $first->vendor;
        }

        if ($first->vendor_id) {
            return Vendor::query()->find($first->vendor_id);
        }

        return null;
    }

    /**
     * @return Collection<int, object{item_name: string, description: string, quantity: float, unit: string, unit_price: float, total_price: float, specifications: string}>
     */
    public function rowsToPoLineObjects(Collection $rows): Collection
    {
        return $rows->map(function (PriceComparison $row) {
            $qty = max(0.0, (float) ($row->quantity ?? 0));
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $unit = (float) ($row->unit_price ?? 0);
            $total = (float) ($row->total_price ?? 0);
            if ($total <= 0) {
                $total = round($unit * $qty, 2);
            }

            $description = trim((string) ($row->item_description ?? ''));

            return (object) [
                'item_name' => $description !== '' ? $description : 'Item',
                'description' => '',
                'quantity' => $qty,
                'unit' => 'unit',
                'unit_price' => $unit,
                'total_price' => $total,
                'specifications' => '',
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsToPoLineArrays(Collection $rows): array
    {
        return $this->rowsToPoLineObjects($rows)->map(function ($item) {
            return [
                'name' => $item->item_name,
                'item_name' => $item->item_name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
                'specifications' => $item->specifications,
            ];
        })->values()->all();
    }

    public function subtotalForRows(Collection $rows): float
    {
        return (float) $rows->sum(function (PriceComparison $row) {
            $qty = max(0.0, (float) ($row->quantity ?? 0));
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $unit = (float) ($row->unit_price ?? 0);
            $stored = (float) ($row->total_price ?? 0);

            return $stored > 0 ? $stored : round($unit * $qty, 2);
        });
    }
}
