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
use Illuminate\Support\Str;

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

        try {
            $savedIds = DB::transaction(function () use ($mrf, $rows) {
                $mrf->priceComparisons()->delete();

                $ids = [];
                foreach ($rows as $row) {
                    $publicVendorId = trim((string) ($row['vendor_id'] ?? ''));
                    if ($publicVendorId !== '') {
                        $vendor = Vendor::query()->where('vendor_id', $publicVendorId)->first();
                        if (!$vendor) {
                            throw new \InvalidArgumentException(
                                'Vendor not found for code: '.$publicVendorId
                            );
                        }
                        $internalVendorId = $vendor->id;
                    } else {
                        $vendor = $this->createManualVendorFromRow($mrf, $row['manual_vendor'] ?? []);
                        $internalVendorId = $vendor->id;
                    }

                    $unitPrice = (float) $row['unit_price'];
                    $quantity = (float) $row['quantity'];
                    $isSelected = filter_var($row['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    $model = PriceComparison::create([
                        'purchase_order_id' => $mrf->id,
                        'vendor_id' => $internalVendorId,
                        'item_description' => $row['item_description'],
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'total_price' => round($unitPrice * $quantity, 2),
                        'is_selected' => $isSelected,
                        'selection_reason' => $row['selection_reason'] ?? null,
                    ]);

                    $ids[] = $model->id;
                }

                return $ids;
            });
        } catch (\InvalidArgumentException $e) {
            Log::warning('Price comparison save rejected', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to persist price comparisons', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to save price comparisons',
                'code' => 'INTERNAL_ERROR',
            ], 500);
        }

        $saved = PriceComparison::query()
            ->whereIn('id', $savedIds)
            ->with(['vendor:id,vendor_id,name'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Price comparison saved',
            'data' => $saved->map(fn (PriceComparison $row) => $this->serializeRow($row))->values(),
        ]);
    }

    /**
     * Procurement-entered supplier not yet in the vendor directory (fast-track / urgent buys).
     *
     * @param  array<string, mixed>  $manual
     */
    private function createManualVendorFromRow(MRF $mrf, array $manual): Vendor
    {
        $name = trim((string) ($manual['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('manual_vendor.name is required for a new supplier row.');
        }

        $email = trim((string) ($manual['email'] ?? ''));
        if ($email !== '' && Vendor::query()->where('email', $email)->exists()) {
            $existing = Vendor::query()->where('email', $email)->first();
            Log::info('Price comparison: reusing existing vendor by email', [
                'mrf_id' => $mrf->mrf_id,
                'vendor_id' => $existing->vendor_id,
            ]);

            return $existing;
        }

        if ($email === '') {
            $slug = Str::slug($mrf->mrf_id ?? 'mrf', '-');
            $email = 'manual-'.$slug.'-'.Str::lower(Str::random(10)).'@supplier.placeholder';
            while (Vendor::query()->where('email', $email)->exists()) {
                $email = 'manual-'.$slug.'-'.Str::lower(Str::random(10)).'@supplier.placeholder';
            }
        }

        $vendor = Vendor::create([
            'vendor_id' => Vendor::generateVendorId(),
            'name' => $name,
            'category' => 'General',
            'rating' => 0,
            'total_orders' => 0,
            'status' => 'Active',
            'email' => $email,
            'phone' => ($manual['phone'] ?? null) !== '' ? trim((string) $manual['phone']) : null,
            'address' => ($manual['address'] ?? null) !== '' ? trim((string) $manual['address']) : null,
            'contact_person' => ($manual['contact_person'] ?? null) !== '' ? trim((string) $manual['contact_person']) : null,
            'contact_person_email' => ($manual['contact_person_email'] ?? null) !== '' ? trim((string) $manual['contact_person_email']) : null,
            'notes' => 'Created from procurement price comparison (manual / fast-track).',
        ]);

        Log::info('Created vendor from manual price-comparison row', [
            'mrf_id' => $mrf->mrf_id,
            'vendor_pk' => $vendor->id,
            'vendor_id' => $vendor->vendor_id,
        ]);

        return $vendor;
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
