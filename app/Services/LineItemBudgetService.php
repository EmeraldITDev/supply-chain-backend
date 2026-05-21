<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\Quotation;
use App\Models\SRF;
use App\Models\SRFItem;
use App\Support\RequestLineItemParser;

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
            $quoted = (float) ($item->quoted_amount ?? $quotedByName[strtolower($item->item_name)] ?? 0);
            $variance = $budget > 0 || $quoted > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatPnLRow($item->id, $item->item_name, $budget, $quoted, $variance);
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
            $quoted = (float) ($item->quoted_amount ?? $item->total_price ?? 0);
            $variance = $budget > 0 || $quoted > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatPnLRow($item->id, $item->item_name, $budget, $quoted, $variance);
        })->all();

        return ['items' => $rows, 'summary' => $this->summarize($rows)];
    }

    public function hydrateMrfQuotedAmounts(MRF $mrf, Quotation $quotation): void
    {
        $quotation->loadMissing('items');
        $byName = $this->quotationItemsByName($quotation);

        foreach ($mrf->items as $line) {
            $key = strtolower((string) $line->item_name);
            if (isset($byName[$key])) {
                $line->quoted_amount = $byName[$key];
                $line->save();
            }
        }
    }

    public function hydrateSrfQuotedAmounts(SRF $srf, float $quotedTotal): void
    {
        $lines = $srf->items()->get();
        if ($lines->isEmpty()) {
            return;
        }

        if ($lines->count() === 1) {
            $lines->first()->update(['quoted_amount' => $quotedTotal]);

            return;
        }

        $perLine = round($quotedTotal / $lines->count(), 2);
        foreach ($lines as $line) {
            $line->update(['quoted_amount' => $perLine]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems  Normalized rows from RequestLineItemParser
     */
    public function syncMrfItems(MRF $mrf, array $lineItems): void
    {
        $mrf->items()->delete();
        foreach ($lineItems as $row) {
            $normalized = RequestLineItemParser::normalizeRow(is_array($row) ? $row : []);
            $qty = (int) ($normalized['quantity'] ?? 1);
            $unitPrice = $normalized['unit_price'] ?? null;

            MRFItem::create([
                'mrf_id' => $mrf->id,
                'item_name' => $normalized['item_name'],
                'description' => $normalized['description'] ?? null,
                'quantity' => $qty,
                'unit' => $normalized['unit'] ?? 'unit',
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice !== null ? $unitPrice * $qty : null,
                'budget_amount' => $normalized['budget_amount'],
                'specifications' => $normalized['specifications'] ?? null,
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
            $normalized = RequestLineItemParser::normalizeRow(is_array($row) ? $row : []);
            $qty = (int) ($normalized['quantity'] ?? 1);

            SRFItem::create([
                'srf_id' => $srf->id,
                'item_name' => $normalized['item_name'],
                'description' => $normalized['description'] ?? null,
                'quantity' => $qty,
                'unit' => $normalized['unit'] ?? 'unit',
                'budget_amount' => $normalized['budget_amount'],
                'specifications' => $normalized['specifications'] ?? null,
            ]);
        }
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
     * Shape expected by frontend LineItemPnLSection.
     *
     * @return array<string, mixed>
     */
    private function formatPnLRow(int $id, string $name, float $budget, float $quoted, float $variance): array
    {
        return [
            'id' => $id,
            'itemName' => $name,
            'item_name' => $name,
            'budgetAmount' => $budget,
            'budget_amount' => $budget,
            'quotedAmount' => $quoted,
            'quoted_amount' => $quoted,
            'variance' => $variance,
            'varianceType' => $variance > 0 ? 'saving' : ($variance < 0 ? 'loss' : 'neutral'),
            'variance_type' => $variance > 0 ? 'saving' : ($variance < 0 ? 'loss' : 'neutral'),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function quotedTotalsByItemName(MRF $mrf): array
    {
        $quotations = Quotation::query()
            ->whereHas('rfq', fn ($q) => $q->where('mrf_id', $mrf->id))
            ->with('items')
            ->get();

        $vendorId = $mrf->selected_vendor_id;
        $selected = $vendorId
            ? $quotations->firstWhere('vendor_id', $vendorId)
            : $quotations->where('status', 'Approved')->sortBy('total_amount')->first();
        $selected ??= $quotations->sortBy('total_amount')->first();

        if (!$selected) {
            return [];
        }

        return $this->quotationItemsByName($selected);
    }

    /**
     * @return array<string, float>
     */
    private function quotationItemsByName(Quotation $quotation): array
    {
        $map = [];
        foreach ($quotation->items as $item) {
            $key = strtolower((string) $item->item_name);
            $map[$key] = ($map[$key] ?? 0) + (float) ($item->total_price ?? 0);
        }

        return $map;
    }
}
