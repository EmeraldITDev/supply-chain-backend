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
            $rows = $this->syntheticMrfPnLRows($mrf);

            return $this->enrichPnlPayload([
                'items' => $rows,
                'summary' => $rows === [] ? $this->emptySummary() : $this->summarize($rows),
            ]);
        }

        $quotedByName = $this->quotedTotalsByItemName($mrf);

        $rows = $items->map(function (MRFItem $item) use ($quotedByName) {
            $budget = (float) ($item->budget_amount ?? 0);
            $quoted = (float) ($item->quoted_amount ?? $quotedByName[strtolower($item->item_name)] ?? 0);
            $variance = $budget > 0 || $quoted > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatPnLRow($item->id, $item->item_name, $budget, $quoted, $variance);
        })->all();

        return $this->enrichPnlPayload(['items' => $rows, 'summary' => $this->summarize($rows)]);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int>}
     */
    public function srfProfitAndLoss(SRF $srf): array
    {
        $items = $srf->items()->get();
        if ($items->isEmpty()) {
            $name = trim((string) ($srf->title ?: $srf->description ?: 'SRF line'));
            $budget = (float) ($srf->estimated_cost ?? 0);
            $quoted = (float) ($srf->quoted_amount ?? $srf->total_price ?? 0);
            $rows = ($budget > 0 || $quoted > 0)
                ? [$this->formatPnLRow(0, $name, $budget, $quoted, round($budget - $quoted, 2))]
                : [];

            return $this->enrichPnlPayload([
                'items' => $rows,
                'summary' => $rows === [] ? $this->emptySummary() : $this->summarize($rows),
            ]);
        }

        $rows = $items->map(function (SRFItem $item) {
            $budget = (float) ($item->budget_amount ?? 0);
            $quoted = (float) ($item->quoted_amount ?? $item->total_price ?? 0);
            $variance = $budget > 0 || $quoted > 0 ? round($budget - $quoted, 2) : 0.0;

            return $this->formatPnLRow($item->id, $item->item_name, $budget, $quoted, $variance);
        })->all();

        return $this->enrichPnlPayload(['items' => $rows, 'summary' => $this->summarize($rows)]);
    }

    /**
     * Add aliases expected by frontend normalizeProfitAndLoss().
     *
     * @param  array{items: array<int, array<string, mixed>>, summary: array<string, float|int>}  $pnl
     * @return array<string, mixed>
     */
    public function enrichPnlPayload(array $pnl): array
    {
        $items = $pnl['items'] ?? [];
        $summary = $pnl['summary'] ?? $this->emptySummary();

        return array_merge($pnl, [
            'items' => $items,
            'line_items' => $items,
            'lineItems' => $items,
            'rows' => $items,
            'summary' => $this->enrichSummary($summary),
        ]);
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
            'name' => $name,
            'description' => $name,
            'budgetAmount' => $budget,
            'budget_amount' => $budget,
            'budget' => $budget,
            'estimated_amount' => $budget,
            'quotedAmount' => $quoted,
            'quoted_amount' => $quoted,
            'actual_amount' => $quoted,
            'total_price' => $quoted,
            'variance' => $variance,
            'varianceType' => $variance > 0 ? 'saving' : ($variance < 0 ? 'loss' : 'neutral'),
            'variance_type' => $variance > 0 ? 'saving' : ($variance < 0 ? 'loss' : 'neutral'),
        ];
    }

    /**
     * Legacy/header-only MRFs often have no mrf_line_items rows. Build one synthetic
     * P&L row from MRF header fields + winning quotation or price comparison.
     *
     * @return list<array<string, mixed>>
     */
    private function syntheticMrfPnLRows(MRF $mrf): array
    {
        $name = trim((string) ($mrf->title ?: $mrf->description ?: 'MRF line'));
        $budget = (float) ($mrf->estimated_cost ?? 0);
        $quoted = $this->resolveMrfQuotedTotal($mrf);

        if ($budget <= 0 && $quoted <= 0) {
            return [];
        }

        return [
            $this->formatPnLRow(0, $name, $budget, $quoted, round($budget - $quoted, 2)),
        ];
    }

    private function resolveMrfQuotedTotal(MRF $mrf): float
    {
        $quotedByName = $this->quotedTotalsByItemName($mrf);
        if ($quotedByName !== []) {
            return round(array_sum($quotedByName), 2);
        }

        $mrf->loadMissing(['priceComparisons', 'rfqs.quotations']);

        $selectedComparison = $mrf->priceComparisons->firstWhere('is_selected', true);
        if ($selectedComparison) {
            return (float) ($selectedComparison->total_price ?? 0);
        }

        $quotations = $mrf->rfqs
            ->flatMap(fn ($rfq) => $rfq->quotations)
            ->filter();

        if ($mrf->selected_vendor_id) {
            $match = $quotations->firstWhere('vendor_id', $mrf->selected_vendor_id);
            if ($match) {
                return (float) ($match->total_amount ?? $match->price ?? 0);
            }
        }

        $approved = $quotations->where('status', 'Approved')->sortBy('total_amount')->first();
        if ($approved) {
            return (float) ($approved->total_amount ?? $approved->price ?? 0);
        }

        $lowest = $quotations->sortBy('total_amount')->first();

        return $lowest ? (float) ($lowest->total_amount ?? $lowest->price ?? 0) : 0.0;
    }

    /**
     * @param  array<string, float|int>  $summary
     * @return array<string, float|int>
     */
    private function enrichSummary(array $summary): array
    {
        return array_merge($summary, [
            'total_budget' => $summary['totalBudget'] ?? 0,
            'total_quoted' => $summary['totalQuoted'] ?? 0,
            'net_variance' => $summary['netVariance'] ?? 0,
            'total_savings' => $summary['totalSavings'] ?? 0,
            'total_loss' => $summary['totalLoss'] ?? 0,
            'line_count' => $summary['lineCount'] ?? 0,
        ]);
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
