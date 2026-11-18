<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuickBooksException;
use App\Exceptions\QuickBooksSupplierConflictException;
use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\QuickBooksVendorCreationRequest;
use App\Models\Vendor;
use App\Models\VendorQuickBooksMapping;
use App\Services\QuickBooksDiscoveryService;
use App\Services\QuickBooksPOSyncService;
use App\Services\QuickBooksPullService;
use App\Services\QuickBooksService;
use App\Services\QuickBooksTokenService;
use App\Services\QuickBooksSupplierService;
use App\Services\ScmAuditService;
use App\Support\UserRoleNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QuickBooksController extends Controller
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksDiscoveryService $discovery,
        private QuickBooksService $quickBooks,
        private QuickBooksPOSyncService $syncService,
        private QuickBooksPullService $pullService,
        private QuickBooksSupplierService $supplierService,
    ) {}

    public function connect(): JsonResponse
    {
        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['issued_at' => now()->getTimestamp()], now()->addMinutes(10));

        $endpoint = $this->discovery->authorizationEndpoint();
        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $url = $endpoint.$separator.http_build_query([
            'client_id' => config('services.quickbooks.client_id'),
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('services.quickbooks.scopes'),
            'state' => $state,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_url' => $url,
            ],
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $stateValid = is_string($state) && preg_match('/^[A-Za-z0-9]{40}$/', $state) === 1;
        $pulled = $stateValid ? Cache::pull($this->stateKey($state)) : null;
        if (! $stateValid || $pulled === null) {
            return $this->frontendRedirect('invalid_state');
        }

        if ($request->query('error') === 'access_denied') {
            return $this->frontendRedirect('access_denied');
        }

        $code = $request->query('code');
        $realmId = $request->query('realmId');
        if (! is_string($code) || $code === '' || ! is_string($realmId) || $realmId === '') {
            return $this->frontendRedirect('token_exchange_failed');
        }

        try {
            $this->tokens->exchangeAuthorizationCode($code, $realmId);
        } catch (\Throwable) {
            return $this->frontendRedirect('token_exchange_failed');
        }

        return $this->frontendRedirect(null);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_merge($this->tokens->getConnectionStatus(), [
                'dry_run' => config('services.quickbooks.dry_run') === true,
                'writes_enabled' => config('services.quickbooks.writes_enabled') === true,
                'default_line_ref_configured' => filled(config('services.quickbooks.default_item_id'))
                    || filled(config('services.quickbooks.default_expense_account_id')),
            ]),
        ]);
    }

    public function disconnect(Request $request, ScmAuditService $audit): JsonResponse
    {
        $this->tokens->disconnect($this->discovery);

        $actor = $request->user();
        $audit->record(
            'quickbooks_disconnect',
            'QuickBooks',
            null,
            $actor,
            'QuickBooks connection revoked for the company.',
            [
                'user_id' => $actor?->id,
                'role' => $actor ? UserRoleNormalizer::supplyChainRole($actor) : null,
                'timestamp' => now()->toIso8601String(),
                'action' => 'quickbooks_disconnect',
            ],
            $request,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'connected' => false,
            ],
        ]);
    }

    public function syncPo(string $mrfId): JsonResponse
    {
        $mrf = $this->findMrf($mrfId);
        if ($mrf === null) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found.',
            ], 404);
        }

        try {
            $result = $this->syncService->sync($mrf);
        } catch (QuickBooksException $e) {
            return $this->quickBooksError($e);
        }

        unset($result['payload']);
        $blocked = $result['blocked_reason'] ?? null;
        $status = match ($blocked) {
            'validation_failed' => 422,
            'writes_disabled' => 409,
            'currency_invalid', 'vendor_mapping_required', 'vendor_currency_unconfirmed', 'vendor_currency_mismatch' => 422,
            default => 200,
        };
        $code = match ($blocked) {
            'validation_failed' => 'QB_VALIDATION_FAILED',
            'writes_disabled' => 'QB_WRITES_DISABLED',
            'currency_invalid' => 'QB_CURRENCY_INVALID',
            'vendor_mapping_required' => 'QB_VENDOR_MAPPING_REQUIRED',
            'vendor_currency_unconfirmed' => 'QB_VENDOR_CURRENCY_UNCONFIRMED',
            'vendor_currency_mismatch' => 'QB_VENDOR_CURRENCY_MISMATCH',
            default => null,
        };

        return response()->json(array_filter([
            'success' => (bool) $result['passed'],
            'code' => $code,
            'message' => match ($code) {
                'QB_VALIDATION_FAILED' => 'This purchase order did not pass QuickBooks validation.',
                'QB_WRITES_DISABLED' => 'QuickBooks writes are disabled.',
                'QB_CURRENCY_INVALID' => 'The purchase order has no supported currency.',
                'QB_VENDOR_MAPPING_REQUIRED' => 'A verified QuickBooks vendor mapping is required.',
                'QB_VENDOR_CURRENCY_UNCONFIRMED' => 'The QuickBooks vendor currency could not be confirmed.',
                'QB_VENDOR_CURRENCY_MISMATCH' => 'The QuickBooks vendor currency does not match the purchase order.',
                default => null,
            },
            'dry_run' => (bool) $result['dry_run'],
            'data' => $result,
        ], fn ($value) => $value !== null), $status);
    }

    public function inspectVendors(): JsonResponse
    {
        return $this->inspected(fn () => $this->quickBooks->inspectVendors(10));
    }

    public function inspectPurchaseOrders(): JsonResponse
    {
        return $this->inspected(fn () => $this->quickBooks->inspectPurchaseOrders(10));
    }

    public function inspectAccountsItems(): JsonResponse
    {
        return $this->inspected(fn () => $this->quickBooks->inspectAccountsAndItems(25));
    }

    public function pullVendors(): JsonResponse
    {
        return $this->inspected(fn () => $this->pullService->pullVendors());
    }

    public function supplierReconciliation(): JsonResponse
    {
        return $this->inspected(fn () => $this->supplierService->reconciliationPreview());
    }

    public function supplierBulkPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'search' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->inspected(fn () => $this->supplierService->bulkClassificationPreview(
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25),
            $data['search'] ?? null,
            $data['filter'] ?? 'all',
        ));
    }

    public function bulkImportSuppliers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:50'],
            'records.*.quickbooks_vendor_id' => ['required', 'string', 'max:64'],
            'records.*.currency' => ['required', 'string', 'size:3'],
            'records.*.manual_override' => ['sometimes', 'boolean'],
            'records.*.justification' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->supplierService->bulkImport($data['records'], (int) $request->user()->id),
        ], 207);
    }

    public function bulkExcludeSuppliers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:50'],
            'records.*.quickbooks_vendor_id' => ['required', 'string', 'max:64'],
            'records.*.currency' => ['required', 'string', 'size:3'],
            'records.*.reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->supplierService->bulkExclude($data['records'], (int) $request->user()->id),
        ], 207);
    }

    public function bulkRestoreSupplierExclusions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exclusion_ids' => ['required', 'array', 'min:1', 'max:50'],
            'exclusion_ids.*' => ['required', 'integer'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->supplierService->bulkRestore($data['exclusion_ids'], (int) $request->user()->id),
        ], 207);
    }

    public function approveSupplierMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'quickbooks_vendor_id' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'match_method' => ['required', 'string', 'max:32'],
        ]);
        try {
            $mapping = $this->supplierService->approveMapping(
                Vendor::findOrFail($data['vendor_id']),
                $data['quickbooks_vendor_id'],
                $data['currency'],
                $data['match_method'],
                (int) $request->user()->id,
            );
        } catch (QuickBooksSupplierConflictException $exception) {
            return response()->json([
                'success' => false,
                'code' => 'QB_SUPPLIER_IDENTITY_CONFLICT',
                'message' => $exception->getMessage(),
                'conflict_type' => $exception->conflictType,
                'conflict' => array_merge($exception->details, [
                    'recommended_resolution' => $exception->recommendedResolution,
                ]),
            ], 409);
        }

        return response()->json(['success' => true, 'data' => $mapping], 201);
    }

    public function importSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quickbooks_vendor_id' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $result = $this->supplierService->importVendor(
            $data['quickbooks_vendor_id'],
            $data['currency'],
            (int) $request->user()->id,
        );

        return response()->json(['success' => true, 'data' => $result], 201);
    }

    public function decideSupplierMapping(Request $request, VendorQuickBooksMapping $mapping): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:rejected,deferred']]);
        $mapping = $this->supplierService->decideMapping($mapping, $data['status'], (int) $request->user()->id);

        return response()->json(['success' => true, 'data' => $mapping]);
    }

    public function prepareSupplierCreation(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', 'string', 'size:3']]);
        $creationRequest = $this->supplierService->prepareCreation($vendor, $data['currency'], (int) $request->user()->id);

        return response()->json(['success' => true, 'data' => $creationRequest], 201);
    }

    public function approveSupplierCreation(Request $request, QuickBooksVendorCreationRequest $creationRequest): JsonResponse
    {
        $data = $request->validate(['decision_notes' => ['nullable', 'string', 'max:2000']]);
        $creationRequest = $this->supplierService->approveCreation(
            $creationRequest,
            (int) $request->user()->id,
            $data['decision_notes'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Approved for controlled QuickBooks vendor creation; no QuickBooks write was performed.',
            'data' => $creationRequest,
        ]);
    }

    public function supplierMappings(Vendor $vendor): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->supplierService->mappingsFor($vendor)]);
    }

    public function pullPurchaseOrders(): JsonResponse
    {
        return $this->inspected(fn () => $this->pullService->pullPurchaseOrders());
    }

    private function inspected(\Closure $callback): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
            ]);
        } catch (QuickBooksException $e) {
            return $this->quickBooksError($e);
        }
    }

    private function quickBooksError(QuickBooksException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $this->quickBooksErrorCode($e),
            'message' => $e->getMessage(),
            'fault_code' => $e->faultCode,
        ], $e->status);
    }

    private function quickBooksErrorCode(QuickBooksException $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'not connected')) {
            return 'QB_NOT_CONNECTED';
        }
        if (str_contains($message, 'expired') || str_contains($message, 'Reconnect')) {
            return 'QB_NEEDS_RECONNECT';
        }
        if (str_contains($message, 'writes are disabled')) {
            return 'QB_WRITES_DISABLED';
        }

        return 'QB_SYNC_FAILED';
    }

    private function findMrf(string $mrfId): ?MRF
    {
        return MRF::query()->where(function ($query) use ($mrfId) {
            $query->where('mrf_id', $mrfId)->orWhere('formatted_id', $mrfId);
            if (ctype_digit($mrfId)) {
                $query->orWhere('id', (int) $mrfId);
            }
        })->first();
    }

    private function frontendRedirect(?string $error): RedirectResponse
    {
        $base = rtrim((string) config('services.frontend_url'), '/');
        $url = $base.'/settings?tab=integrations';
        if ($error === null) {
            $url .= '&quickbooks=connected';
        } elseif (in_array($error, ['invalid_state', 'access_denied', 'token_exchange_failed'], true)) {
            $url .= '&error='.$error;
        }

        return redirect()->away($url, 302)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, no-cache');
    }

    private function stateKey(string $state): string
    {
        return 'qb_oauth_state_'.$state;
    }
}
