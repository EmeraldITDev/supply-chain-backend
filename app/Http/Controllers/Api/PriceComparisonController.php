<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePriceComparisonsRequest;
use App\Models\MRF;
use App\Models\PriceComparison;
use App\Models\Vendor;
use App\Services\ManualVendorOnboardingService;
use App\Services\PaymentScheduleService;
use App\Support\ProcurementOverviewAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PriceComparisonController extends Controller
{
    public function __construct(
        private ManualVendorOnboardingService $manualVendorOnboarding,
    ) {
    }

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
        $allowed = array_merge(
            ['procurement_manager', 'procurement', 'supply_chain_director', 'admin'],
            ProcurementOverviewAccess::OVERVIEW_ROLES,
        );

        if (!$user || !in_array($user->scmRole(), $allowed, true)) {
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

        $schedule = app(PaymentScheduleService::class)->findForMrf($mrf);
        $scheduleSummary = $schedule ? app(PaymentScheduleService::class)->summaryText($schedule) : null;

        $rows = $mrf->priceComparisons()
            ->with('vendor:id,vendor_id,name')
            ->orderByDesc('is_selected')
            ->orderBy('id')
            ->get()
            ->map(fn (PriceComparison $row) => $this->serializeRow($row, $scheduleSummary));

        return response()->json([
            'success' => true,
            'paymentSchedule' => $schedule ? app(PaymentScheduleService::class)->toApiArray($schedule) : null,
            'payment_schedule' => $schedule ? app(PaymentScheduleService::class)->toApiArray($schedule) : null,
            'data' => $rows->values(),
        ]);
    }

    public function bulkReplace(StorePriceComparisonsRequest $request, string $id): JsonResponse
    {
        if (ProcurementOverviewAccess::isProcurementOverviewOnly($request->user())) {
            return response()->json([
                'success' => false,
                'error' => 'Procurement overview access is read-only',
                'code' => 'FORBIDDEN',
            ], 403);
        }

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
                /** @var array<string, Vendor> $vendorCache */
                $vendorCache = [];

                // Batch-resolve directory vendors once (avoids N+1 exists/select per row).
                $publicVendorIds = collect($rows)
                    ->map(fn ($row) => trim((string) ($row['vendor_id'] ?? '')))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                /** @var \Illuminate\Support\Collection<string, Vendor> $vendorsByCode */
                $vendorsByCode = $publicVendorIds === []
                    ? collect()
                    : Vendor::query()
                        ->whereIn('vendor_id', $publicVendorIds)
                        ->get()
                        ->keyBy('vendor_id');

                $now = now();
                $insertRows = [];

                foreach ($rows as $row) {
                    $publicVendorId = trim((string) ($row['vendor_id'] ?? ''));
                    if ($publicVendorId !== '') {
                        $vendor = $vendorsByCode->get($publicVendorId);
                        if (!$vendor) {
                            throw new \InvalidArgumentException(
                                'Vendor not found for code: '.$publicVendorId
                            );
                        }
                        $internalVendorId = $vendor->id;
                    } else {
                        $manual = $row['manual_vendor'] ?? [];
                        $emailKey = Vendor::normalizeEmail((string) ($manual['email'] ?? ''));
                        $nameKey = Vendor::normalizeName((string) ($manual['name'] ?? ''));
                        $cacheKey = $emailKey !== '' ? 'email:'.$emailKey : ($nameKey !== '' ? 'name:'.$nameKey : null);

                        if ($cacheKey && isset($vendorCache[$cacheKey])) {
                            $vendor = $vendorCache[$cacheKey];
                        } else {
                            $result = $this->manualVendorOnboarding->findOrCreateFromManual($manual, $mrf);
                            $vendor = $result['vendor'];
                            if ($cacheKey) {
                                $vendorCache[$cacheKey] = $vendor;
                            }
                        }

                        $internalVendorId = $vendor->id;
                    }

                    $unitPrice = (float) $row['unit_price'];
                    $quantity = (float) $row['quantity'];
                    $isSelected = filter_var($row['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    $insertRows[] = [
                        'purchase_order_id' => $mrf->id,
                        'vendor_id' => $internalVendorId,
                        'item_description' => $row['item_description'],
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'total_price' => round($unitPrice * $quantity, 2),
                        'is_selected' => $isSelected,
                        'selection_reason' => $row['selection_reason'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insertRows !== []) {
                    PriceComparison::query()->insert($insertRows);
                    $ids = PriceComparison::query()
                        ->where('purchase_order_id', $mrf->id)
                        ->orderBy('id')
                        ->pluck('id')
                        ->all();
                }

                return $ids;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?? 'Validation failed',
                'errors' => $e->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
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

        $schedule = app(PaymentScheduleService::class)->findForMrf($mrf);
        $scheduleSummary = $schedule ? app(PaymentScheduleService::class)->summaryText($schedule) : null;

        return response()->json([
            'success' => true,
            'message' => 'Price comparison saved',
            'data' => $saved->map(fn (PriceComparison $row) => $this->serializeRow($row, $scheduleSummary))->values(),
        ]);
    }

    private function serializeRow(PriceComparison $row, ?string $scheduleSummary = null): array
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
            'paymentTerms' => $scheduleSummary,
            'payment_terms' => $scheduleSummary,
            'paymentScheduleSummary' => $scheduleSummary,
            'payment_schedule_summary' => $scheduleSummary,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
