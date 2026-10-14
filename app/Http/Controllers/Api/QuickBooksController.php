<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuickBooksException;
use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\QuickBooksDiscoveryService;
use App\Services\QuickBooksPOSyncService;
use App\Services\QuickBooksPullService;
use App\Services\QuickBooksService;
use App\Services\QuickBooksTokenService;
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
            ]),
        ]);
    }

    public function disconnect(): JsonResponse
    {
        $this->tokens->disconnect($this->discovery);

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
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'fault_code' => $e->faultCode,
            ], $e->status);
        }

        unset($result['payload']);
        $status = match ($result['blocked_reason'] ?? null) {
            'validation_failed' => 422,
            'writes_disabled' => 409,
            default => 200,
        };

        return response()->json([
            'success' => (bool) $result['passed'],
            'dry_run' => (bool) $result['dry_run'],
            'data' => $result,
        ], $status);
    }

    public function inspectVendors(): JsonResponse
    {
        return $this->inspected(fn () => $this->quickBooks->inspectVendors(10));
    }

    public function inspectPurchaseOrders(): JsonResponse
    {
        return $this->inspected(fn () => $this->quickBooks->inspectPurchaseOrders(10));
    }

    public function pullVendors(): JsonResponse
    {
        return $this->inspected(fn () => $this->pullService->pullVendors());
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
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'fault_code' => $e->faultCode,
            ], $e->status);
        }
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
