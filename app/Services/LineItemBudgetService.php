<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SRF;
use App\Models\SRFItem;
use Illuminate\Support\Collection;

class LineItemBudgetService
{
    /**
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int>}
     */
    public function mrfProfitAndLoss(MRF $mrf): array
    {
        $items = $mrf->items()->get();
        if ($items->isEmpty()) {
            return ['items' => [], 'summary' => $this->emptySummary()];
        }

        $quotedByName = $this->quotedTotalsByItemName($mrf);

        $rows = $items->map(function (MRFItem $item) use ($quotedByName) {
            $budget = (float) ($item->budget_amount ?? 0);
            $quoted = (float) ($item->quoted_total ?? $quotedByName[strtolower($item->item_name)] ?? 0);
            $variance = $budget > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatRow($item->id, $item->item_name, $item->quantity, $item->unit, $budget, $quoted, $variance);
        })->all();

        return ['items' => $rows, 'summary' => $this->summarize($rows)];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int>}
     */
    public function srfProfitAndLoss(SRF $srf): array
    {
        $items = $srf->items()->get();
        if ($items->isEmpty()) {
            return ['items' => [], 'summary' => $this->emptySummary()];
        }

        $rows = $items->map(function (SRFItem $item) {
            $budget = (float) ($item->budget_amount ?? 0);
            $quoted = (float) ($item->quoted_total ?? $item->total_price ?? 0);
            $variance = $budget > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatRow($item->id, $item->item_name, $item->quantity, $item->unit, $budget, $quoted, $variance);
        })->all();

        return ['items' => $rows, 'summary' => $this->summarize($rows)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function summarize(array $rows): array
    {
        $totalBudget = 0.0;
        $totalQuoted = 0.0;
        $totalSavings = 0.0;
        $totalLoss = 0.0;

        foreach ($rows as $row) {
            $totalBudget += (float) $row['budgetAmount'];
            $totalQuoted += (float) $row['quotedAmount'];
            if ($row['variance'] > 0) {
                $totalSavings += (float) $row['variance'];
            } elseif ($row['variance'] < 0) {
                $totalLoss += abs((float) $row['variance']);
            }
        }

        return [
            'totalBudget' => round($totalBudget, 2),
            'totalQuoted' => round($totalQuoted, 2),
            'netVariance' => round($totalBudget - $totalQuoted, 2),
            'totalSavings' => round($totalSavings, 2),
            'totalLoss' => round($totalLoss, 2),
            'lineCount' => count($rows),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptySummary(): array
    {
        return [
            'totalBudget' => 0,
            'totalQuoted' => 0,
            'netVariance' => 0,
            'totalSavings' => 0,
            'totalLoss' => 0,
            'lineCount' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(
        int $id,
        string $name,
        int $qty,
        string $unit,
        float $budget,
        float $quoted,
        float $variance
    ): array {
        return [
            'id' => $id,
            'itemName' => $name,
            'quantity' => $qty,
            'unit' => $unit,
            'budgetAmount' => $budget,
            'quotedAmount' => $quoted,
            'variance' => $variance,
            'varianceType' => $variance > 0 ? 'saving' : ($variance < 0 ? 'loss' : 'neutral'),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function quotedTotalsByItemName(MRF $mrf): array
    {
        $quotations = Quotation::query()
            ->whereHas('rfq', fn ($q) => $q->where('mrf_id', $mrf->id))
            ->where('status', 'Approved')
            ->with('items')
            ->get();

        if ($quotations->isEmpty()) {
            $quotations = Quotation::query()
                ->whereHas('rfq', fn ($q) => $q->where('mrf_id', $mrf->id))
                ->with('items')
                ->get();
        }

        $vendorId = $mrf->selected_vendor_id;
        $selected = $vendorId
            ? $quotations->firstWhere('vendor_id', $vendorId)
            : $quotations->where('status', 'Approved')->sortBy('total_amount')->first();
        $selected ??= $quotations->sortBy('total_amount')->first();
        if (!$selected) {
            return [];
        }

        $map = [];
        foreach ($selected->items as $item) {
            $key = strtolower((string) $item->item_name);
            $map[$key] = ($map[$key] ?? 0) + (float) ($item->total_price ?? 0);
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function syncMrfItems(MRF $mrf, array $lineItems): void
    {
        $mrf->items()->delete();
        foreach ($lineItems as $row) {
            $qty = (int) ($row['quantity'] ?? 1);
            $unitPrice = isset($row['unitPrice']) ? (float) $row['unitPrice'] : null;
            MRFItem::create([
                'mrf_id' => $mrf->id,
                'item_name' => $row['itemName'] ?? $row['item_name'] ?? 'Item',
                'description' => $row['description'] ?? null,
                'quantity' => $qty,
                'unit' => $row['unit'] ?? 'unit',
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice !== null ? $unitPrice * $qty : null,
                'budget_amount' => isset($row['budgetAmount']) ? (float) $row['budgetAmount'] : (isset($row['budget_amount']) ? (float) $row['budget_amount'] : null),
                'specifications' => $row['specifications'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function syncSrfItems(SRF $srf, array $lineItems): void
    {
        $srf->items()->delete();
        foreach ($lineItems as $row) {
            $qty = (int) ($row['quantity'] ?? 1);
            SRFItem::create([
                'srf_id' => $srf->id,
                'item_name' => $row['itemName'] ?? $row['item_name'] ?? 'Item',
                'description' => $row['description'] ?? null,
                'quantity' => $qty,
                'unit' => $row['unit'] ?? 'unit',
                'budget_amount' => isset($row['budgetAmount']) ? (float) $row['budgetAmount'] : (isset($row['budget_amount']) ? (float) $row['budget_amount'] : null),
                'specifications' => $row['specifications'] ?? null,
            ]);
        }
    }
}
