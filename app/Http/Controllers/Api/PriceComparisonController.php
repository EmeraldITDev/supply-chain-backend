<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePriceComparisonsRequest;
use App\Models\MRF;
use App\Models\PriceComparison;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PriceComparisonController extends Controller
{
    private function findMrfByAnyId(string $id): ?MRF
    {
        return MRF::where(function ($query) use ($id) {
            $query->where('mrf_id', $id)
                ->orWhere('formatted_id', $id);

            if (is_numeric($id)) {
                $query->orWhere('id', (int) $id);
            }
        })->first();
    }

    private function ensureViewPermissions(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $allowed = ['procurement_manager', 'procurement', 'supply_chain_director', 'admin'];

        if (!$user || !in_array($user->role, $allowed, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return null;
    }

    public function index(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->ensureViewPermissions($request)) {
            return $denied;
        }

        $mrf = $this->findMrfByAnyId($id);
        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if ($mrf->priceComparisons()->count() === 0) {
            $mrf->syncPriceComparisonsFromQuotations();
        }

        $rows = $mrf->priceComparisons()
            ->with('vendor:id,vendor_id,name')
            ->orderByDesc('is_selected')
            ->orderBy('id')
            ->get()
            ->map(fn (PriceComparison $row) => $this->serializeRow($row));

        return response()->json([
            'success' => true,
            'data' => $rows->values(),
        ]);
    }

    public function bulkReplace(StorePriceComparisonsRequest $request, string $id): JsonResponse
    {
        $mrf = $this->findMrfByAnyId($id);
        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (!empty(trim((string) $mrf->signed_po_url))) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot modify price comparison after the PO has been signed.',
                'code' => 'PO_ALREADY_SIGNED',
            ], 422);
        }

        $rows = $request->validated()['rows'];

        // Resolve vendor string IDs (e.g. VND-001) → numeric vendors.id once
        $vendorStringIds = collect($rows)->pluck('vendor_id')->unique()->values();
        $vendorMap = Vendor::whereIn('vendor_id', $vendorStringIds)
            ->pluck('id', 'vendor_id');

        $missing = $vendorStringIds->diff($vendorMap->keys());
        if ($missing->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'Unknown vendor identifier(s): ' . $missing->implode(', '),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        try {
            $saved = DB::transaction(function () use ($mrf, $rows, $vendorMap) {
                $mrf->priceComparisons()->delete();

                $created = [];
                foreach ($rows as $row) {
                    $unitPrice = (float) $row['unit_price'];
                    $quantity = (float) $row['quantity'];
                    $isSelected = filter_var($row['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    $created[] = PriceComparison::create([
                        'purchase_order_id' => $mrf->id,
                        'vendor_id' => $vendorMap[$row['vendor_id']],
                        'item_description' => $row['item_description'],
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'total_price' => round($unitPrice * $quantity, 2),
                        'is_selected' => $isSelected,
                        'selection_reason' => $row['selection_reason'] ?? null,
                    ]);
                }

                return PriceComparison::newCollection($created);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to persist price comparisons', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to save price comparisons',
                'code' => 'INTERNAL_ERROR',
            ], 500);
        }

        $saved->load('vendor:id,vendor_id,name');

        return response()->json([
            'success' => true,
            'message' => 'Price comparison saved',
            'data' => $saved->map(fn (PriceComparison $row) => $this->serializeRow($row))->values(),
        ]);
    }

    private function serializeRow(PriceComparison $row): array
    {
        return [
            'id' => $row->id,
            'purchase_order_id' => $row->purchase_order_id,
            'vendor_id' => $row->vendor?->vendor_id ?? $row->vendor_id,
            'vendor_internal_id' => $row->vendor_id,
            'vendor_name' => $row->vendor?->name,
            'item_description' => $row->item_description,
            'unit_price' => (float) $row->unit_price,
            'quantity' => (float) $row->quantity,
            'total_price' => (float) $row->total_price,
            'is_selected' => (bool) $row->is_selected,
            'selection_reason' => $row->selection_reason,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
