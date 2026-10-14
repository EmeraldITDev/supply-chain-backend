<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksException;
use App\Http\Controllers\Api\QuickBooksController;
use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\QuickBooksService;
use App\Services\QuickBooksTokenService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickBooksIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $plainAccess = 'plain-access-token-value';

    private string $plainRefresh = 'plain-refresh-token-value';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.client_id' => 'test-client-id',
            'services.quickbooks.client_secret' => 'test-client-secret',
            'services.quickbooks.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
            'services.quickbooks.redirect_uri' => 'https://supply-chain-backend-hwh6.onrender.com/api/quickbooks/callback',
            'services.quickbooks.environment' => 'production',
            'services.quickbooks.scopes' => 'com.intuit.quickbooks.accounting',
            'services.quickbooks.dry_run' => true,
            'services.quickbooks.writes_enabled' => false,
            'services.quickbooks.default_item_id' => '88',
            'services.quickbooks.default_expense_account_id' => null,
            'services.quickbooks.ap_account_id' => null,
            'services.quickbooks.minor_version' => 75,
            'services.frontend_url' => 'https://scm.emeraldcfze.com',
        ]);
    }

    public function test_tokens_are_stored_as_ciphertext_and_refresh_expiry_covers_100_days(): void
    {
        app(QuickBooksTokenService::class)->store([
            'access_token' => $this->plainAccess,
            'refresh_token' => $this->plainRefresh,
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 100 * 86400,
        ], 'realm-should-not-leak');

        $storedAccess = SystemSetting::query()->where('key', QuickBooksTokenService::ACCESS_TOKEN)->value('value');
        $storedRefresh = SystemSetting::query()->where('key', QuickBooksTokenService::REFRESH_TOKEN)->value('value');
        $storedRealm = SystemSetting::query()->where('key', QuickBooksTokenService::REALM_ID)->value('value');

        $this->assertIsString($storedAccess);
        $this->assertStringNotContainsString($this->plainAccess, $storedAccess);
        $this->assertStringNotContainsString($this->plainRefresh, (string) $storedRefresh);
        $this->assertStringNotContainsString('realm-should-not-leak', (string) $storedRealm);
        $this->assertFalse(app(QuickBooksTokenService::class)->isRefreshTokenExpired());

        SystemSetting::query()->where('key', QuickBooksTokenService::REFRESH_EXPIRES_AT)->update([
            'value' => now()->subDays(101)->toIso8601String(),
        ]);
        $this->assertTrue(app(QuickBooksTokenService::class)->isRefreshTokenExpired());

        SystemSetting::query()->where('key', QuickBooksTokenService::ACCESS_EXPIRES_AT)->update([
            'value' => now()->addMinutes(4)->toIso8601String(),
        ]);
        $this->assertTrue(app(QuickBooksTokenService::class)->isAccessTokenExpired());

        SystemSetting::query()->where('key', QuickBooksTokenService::ACCESS_EXPIRES_AT)->update([
            'value' => now()->addMinutes(10)->toIso8601String(),
        ]);
        $this->assertFalse(app(QuickBooksTokenService::class)->isAccessTokenExpired());
    }

    public function test_status_endpoint_does_not_leak_secrets(): void
    {
        config([
            'services.quickbooks.default_item_id' => null,
            'services.quickbooks.default_expense_account_id' => null,
        ]);
        $this->storeConnection();
        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'admin']));

        $response = $this->getJson('/api/quickbooks/status');

        $response->assertOk();
        $this->assertSame(
            ['connected', 'needs_reconnect', 'connected_at', 'access_token_expires_at', 'refresh_token_expires_at', 'dry_run', 'writes_enabled', 'default_line_ref_configured'],
            array_keys($response->json('data'))
        );
        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString($this->plainAccess, (string) $encoded);
        $this->assertStringNotContainsString($this->plainRefresh, (string) $encoded);
        $this->assertStringNotContainsString('realm-should-not-leak', (string) $encoded);
        $this->assertStringNotContainsString('test-client-secret', (string) $encoded);
        $this->assertFalse($response->json('data.default_line_ref_configured'));
    }

    public function test_status_reports_when_a_default_line_reference_is_configured(): void
    {
        config(['services.quickbooks.default_item_id' => '88']);
        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'procurement']));

        $this->getJson('/api/quickbooks/status')
            ->assertOk()
            ->assertJsonPath('data.default_line_ref_configured', true)
            ->assertJsonMissingPath('data.realm_id');
    }

    public function test_inspect_accounts_items_is_admin_only_and_returns_trimmed_rows(): void
    {
        $this->storeConnection();
        $this->fakeHttp(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/query')) {
                $params = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
                $query = (string) ($params['query'] ?? '');
                if (str_contains($query, "AccountType = 'Expense'")) {
                    return Http::response([
                        'QueryResponse' => ['Account' => [
                            'Id' => '41',
                            'Name' => 'Office',
                            'AccountType' => 'Expense',
                            'AcctNum' => 'SHOULD_NOT_LEAK',
                        ]],
                    ]);
                }
                if (str_contains($query, 'Accounts Payable')) {
                    return Http::response(['QueryResponse' => ['Account' => []]]);
                }
                if (str_contains($query, 'from Item')) {
                    return Http::response([
                        'QueryResponse' => ['Item' => [
                            'Id' => '88',
                            'Name' => 'Services',
                            'Type' => 'Service',
                            'Description' => 'SHOULD_NOT_LEAK',
                        ]],
                    ]);
                }
            }

            return $this->discoveryResponse($request);
        });

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'employee']));
        $this->getJson('/api/quickbooks/inspect/accounts-items')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'admin']));
        $response = $this->getJson('/api/quickbooks/inspect/accounts-items');
        $response->assertOk()
            ->assertJsonPath('data.accounts.0.Id', '41')
            ->assertJsonPath('data.accounts.0.Name', 'Office')
            ->assertJsonPath('data.accounts.0.AccountType', 'Expense')
            ->assertJsonPath('data.items.0.Id', '88')
            ->assertJsonPath('data.items.0.Type', 'Service');
        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('SHOULD_NOT_LEAK', (string) $encoded);
        $this->assertStringNotContainsString($this->plainAccess, (string) $encoded);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_mrf_and_vendor_responses_include_quickbooks_fields(): void
    {
        $mrf = $this->makeSignedPo();
        $mrf->quickbooks_po_id = 'PO-9';
        $mrf->quickbooks_sync_status = 'Open';
        $mrf->quickbooks_synced_at = now();
        $mrf->save();
        $mrf->selectedVendor->quickbooks_vendor_id = 'V-77';
        $mrf->selectedVendor->save();

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'procurement_manager']));

        $this->getJson('/api/mrfs/'.$mrf->formatted_id.'?for_po=1')
            ->assertOk()
            ->assertJsonPath('quickbooks_po_id', 'PO-9')
            ->assertJsonPath('quickbooks_sync_status', 'Open')
            ->assertJsonPath('signed_po_url', 'https://s3.example/signed.pdf');

        $listRow = $mrf->fresh()->toListApiArray();
        $this->assertSame('PO-9', $listRow['quickbooks_po_id']);
        $this->assertSame('Open', $listRow['quickbooks_sync_status']);
        $this->assertNotEmpty($listRow['quickbooks_synced_at']);
        $this->assertSame('https://s3.example/signed.pdf', $listRow['signed_po_url']);

        $this->getJson('/api/vendors?dropdown=1&search='.$mrf->selectedVendor->vendor_id)
            ->assertOk()
            ->assertJsonPath('data.0.quickbooks_vendor_id', 'V-77');
    }

    public function test_callback_always_redirects_and_has_no_auth_middleware(): void
    {
        $route = collect(app('router')->getRoutes())->first(function ($route) {
            return $route->uri() === 'api/quickbooks/callback';
        });
        $this->assertNotNull($route);
        $this->assertFalse(collect($route->gatherMiddleware())->contains(fn ($middleware) => str_contains((string) $middleware, 'auth')));

        $invalid = $this->get('/api/quickbooks/callback?code=LEAKED_CODE&state=short&realmId=1');
        $invalid->assertStatus(302)
            ->assertRedirect('https://scm.emeraldcfze.com/settings?tab=integrations&error=invalid_state')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $cacheControl = (string) $invalid->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('LEAKED_CODE', (string) $invalid->headers->get('Location'));
        $state = str_repeat('b', 40);
        Cache::put('qb_oauth_state_'.$state, ['issued_at' => 1], now()->addMinutes(10));
        $this->get('/api/quickbooks/callback?error=access_denied&state='.$state.'&code=LEAKED_CODE')
            ->assertStatus(302)
            ->assertRedirect('https://scm.emeraldcfze.com/settings?tab=integrations&error=access_denied');

        $state = str_repeat('c', 40);
        Cache::put('qb_oauth_state_'.$state, ['issued_at' => 1], now()->addMinutes(10));
        $this->get('/api/quickbooks/callback?state='.$state.'&realmId=123')
            ->assertStatus(302)
            ->assertRedirect('https://scm.emeraldcfze.com/settings?tab=integrations&error=token_exchange_failed');

        $this->fakeDiscovery();
        $this->fakeHttp(function (Request $request) {
            if (str_contains($request->url(), 'tokens/bearer')) {
                return Http::response(['error' => 'invalid_grant'], 400);
            }

            return $this->discoveryResponse($request);
        });
        $state = str_repeat('d', 40);
        Cache::put('qb_oauth_state_'.$state, ['issued_at' => 1], now()->addMinutes(10));
        $this->get('/api/quickbooks/callback?code=auth-code&state='.$state.'&realmId=12345')
            ->assertStatus(302)
            ->assertRedirect('https://scm.emeraldcfze.com/settings?tab=integrations&error=token_exchange_failed');
        $this->assertNull(SystemSetting::query()->where('key', QuickBooksTokenService::ACCESS_TOKEN)->value('value'));

        $this->fakeHttp(function (Request $request) {
            if (str_contains($request->url(), 'tokens/bearer')) {
                return Http::response([
                    'access_token' => $this->plainAccess,
                    'refresh_token' => $this->plainRefresh,
                    'expires_in' => 3600,
                    'x_refresh_token_expires_in' => 8640000,
                ]);
            }

            return $this->discoveryResponse($request);
        });
        $state = str_repeat('e', 40);
        Cache::put('qb_oauth_state_'.$state, ['issued_at' => 1], now()->addMinutes(10));
        $success = $this->get('/api/quickbooks/callback?code=auth-code&state='.$state.'&realmId=12345');
        $success->assertStatus(302)
            ->assertRedirect('https://scm.emeraldcfze.com/settings?tab=integrations&quickbooks=connected');
        $this->assertStringNotContainsString('auth-code', (string) $success->headers->get('Location'));
        $stored = (string) SystemSetting::query()->where('key', QuickBooksTokenService::ACCESS_TOKEN)->value('value');
        $this->assertNotSame($this->plainAccess, $stored);
        $this->assertStringNotContainsString($this->plainAccess, $stored);
    }

    public function test_refresh_runs_once_when_a_completed_refresh_is_already_stored(): void
    {
        $this->storeConnection(60);
        $calls = 0;
        $this->fakeHttp(function (Request $request) use (&$calls) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'tokens/bearer')) {
                $calls++;

                return Http::response([
                    'access_token' => 'rotated-access',
                    'refresh_token' => 'rotated-refresh',
                    'expires_in' => 3600,
                    'x_refresh_token_expires_in' => 8640000,
                ]);
            }

            return $this->discoveryResponse($request);
        });

        $tokens = app(QuickBooksTokenService::class);
        $tokens->refresh(false);
        $tokens->refresh(false);

        $this->assertSame(1, $calls);
        $this->assertSame('rotated-refresh', $this->decrypted(QuickBooksTokenService::REFRESH_TOKEN));
    }

    public function test_unauthorized_query_refreshes_once_and_retries_once(): void
    {
        $this->storeConnection(3600);
        $refreshed = false;
        $refreshCalls = 0;
        $this->fakeHttp(function (Request $request) use (&$refreshed, &$refreshCalls) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'tokens/bearer')) {
                $refreshCalls++;
                $refreshed = true;

                return Http::response([
                    'access_token' => 'rotated-access',
                    'refresh_token' => 'rotated-refresh',
                    'expires_in' => 3600,
                    'x_refresh_token_expires_in' => 8640000,
                ]);
            }
            if (str_contains($request->url(), 'quickbooks.api.intuit.com')) {
                if (! $refreshed) {
                    return Http::response([
                        'Fault' => ['Error' => [['code' => '3200', 'Message' => 'SECRET_SHOULD_NOT_BE_LOGGED']]],
                    ], 401, ['intuit_tid' => 'tid-401']);
                }

                return Http::response(['QueryResponse' => []], 200, ['intuit_tid' => 'tid-ok']);
            }

            return $this->discoveryResponse($request);
        });

        app(QuickBooksService::class)->get('/query', [
            'query' => 'select * from CompanyInfo',
            'operation' => 'update',
        ]);
        app(QuickBooksService::class)->get('/query', ['query' => 'select * from CompanyInfo']);

        $this->assertSame(1, $refreshCalls);
        $urls = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->url())
            ->filter(fn ($url) => str_contains($url, 'quickbooks.api.intuit.com'))
            ->values();
        $this->assertGreaterThan(0, $urls->count());
        foreach ($urls as $url) {
            $this->assertSame(1, substr_count($url, '?'));
        }
        $this->assertStringContainsString('operation=update', $urls->first());
        $this->assertStringContainsString('minorversion=75', $urls->first());
    }

    public function test_disconnect_revokes_and_still_deletes_rows_when_revocation_fails(): void
    {
        $this->storeConnection();
        $this->fakeHttp(function (Request $request) {
            if (str_contains($request->url(), 'tokens/revoke')) {
                return Http::response('', 200);
            }

            return $this->discoveryResponse($request);
        });

        app(QuickBooksTokenService::class)->disconnect(app(\App\Services\QuickBooksDiscoveryService::class));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'tokens/revoke'));
        $this->assertSame(0, SystemSetting::query()->where('category', SystemSetting::CATEGORY_QUICKBOOKS)->count());

        $this->storeConnection();
        $logged = $this->captureLogs();
        $this->fakeHttp(function (Request $request) {
            if (str_contains($request->url(), 'tokens/revoke')) {
                return Http::response('SECRET_REVOKE_BODY', 500);
            }

            return $this->discoveryResponse($request);
        });
        app(QuickBooksTokenService::class)->disconnect(app(\App\Services\QuickBooksDiscoveryService::class));

        $this->assertSame(0, SystemSetting::query()->where('category', SystemSetting::CATEGORY_QUICKBOOKS)->count());
        $this->assertTrue(collect($logged)->contains(function (MessageLogged $log) {
            $encoded = (string) json_encode($log->context);

            return $log->message === 'QuickBooks request failed'
                && ! str_contains($encoded, $this->plainRefresh)
                && ! str_contains($encoded, 'SECRET_REVOKE_BODY');
        }));
    }

    public function test_post_is_blocked_while_dry_run_or_writes_are_disabled(): void
    {
        $this->fakeHttp();

        config(['services.quickbooks.dry_run' => true, 'services.quickbooks.writes_enabled' => true]);
        try {
            app(QuickBooksService::class)->post('/purchaseorder', ['Line' => [['Description' => 'SECRET_LINE']]]);
            $this->fail('Dry run allowed a POST.');
        } catch (QuickBooksException $e) {
            $this->assertSame(409, $e->status);
        }

        config(['services.quickbooks.dry_run' => false, 'services.quickbooks.writes_enabled' => false]);
        try {
            app(QuickBooksService::class)->post('/purchaseorder', ['Line' => [['Description' => 'SECRET_LINE']]]);
            $this->fail('Disabled writes allowed a POST.');
        } catch (QuickBooksException $e) {
            $this->assertSame(409, $e->status);
        }

        Http::assertNothingSent();
    }

    public function test_enabled_post_uses_a_single_query_string(): void
    {
        $this->storeConnection();
        config(['services.quickbooks.dry_run' => false, 'services.quickbooks.writes_enabled' => true]);
        $this->fakeHttp(fn () => Http::response(['PurchaseOrder' => ['Id' => '9']], 200, ['intuit_tid' => 'tid']));

        app(QuickBooksService::class)->post('/purchaseorder', ['PrivateNote' => 'SCM'], ['operation' => 'update']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && substr_count($request->url(), '?') === 1
                && str_contains($request->url(), 'operation=update')
                && str_contains($request->url(), 'minorversion=75')
                && ! str_contains($request->url(), '?operation=update?');
        });
    }

    public function test_logs_do_not_contain_quickbooks_payloads(): void
    {
        $this->storeConnection();
        $logged = $this->captureLogs();
        $this->fakeHttp(fn () => Http::response([
            'Fault' => ['Error' => [['code' => '6240', 'Message' => 'SECRET_VENDOR_NAME', 'Detail' => 'SECRET_PAYLOAD']]],
            'Line' => [['Description' => 'SECRET_LINE_ITEM']],
        ], 400, ['intuit_tid' => 'TID123']));

        try {
            app(QuickBooksService::class)->get('/query', ['query' => "select * from Vendor where DisplayName = 'SECRET_VENDOR_NAME'"]);
            $this->fail('The failed query should have thrown.');
        } catch (QuickBooksException $e) {
            $this->assertSame('6240', $e->faultCode);
            $this->assertStringNotContainsString('SECRET_VENDOR_NAME', $e->getMessage());
        }

        $this->assertTrue(collect($logged)->contains(function (MessageLogged $log) {
            $encoded = (string) json_encode($log->context);

            return $log->message === 'QuickBooks request failed'
                && ($log->context['fault_code'] ?? null) === '6240'
                && ($log->context['intuit_tid'] ?? null) === 'TID123'
                && ($log->context['http_status'] ?? null) === 400
                && ! str_contains($encoded, 'SECRET_VENDOR_NAME')
                && ! str_contains($encoded, 'SECRET_PAYLOAD')
                && ! str_contains($encoded, 'SECRET_LINE_ITEM')
                && ! str_contains($encoded, 'DisplayName');
        }));
    }

    public function test_dry_run_sync_does_not_post_or_write_the_purchase_order(): void
    {
        $this->storeConnection();
        $mrf = $this->makeSignedPo();
        $this->fakeHttp(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['posted' => true], 500);
            }
            $url = $request->url();
            if (! str_contains($url, 'quickbooks.api.intuit.com')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/preferences')) {
                return Http::response([
                    'Preferences' => [
                        'CurrencyPrefs' => [
                            'HomeCurrency' => ['value' => 'NGN'],
                            'MultiCurrencyEnabled' => false,
                        ],
                    ],
                ]);
            }
            if (str_contains($url, '/item/')) {
                return Http::response(['Item' => ['Id' => '88']]);
            }
            if (str_contains($url, '/vendor/')) {
                return Http::response(['Vendor' => ['Id' => '77']]);
            }
            if (str_contains($url, '/query')) {
                $params = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
                $query = (string) ($params['query'] ?? '');
                if (str_contains($query, 'Accounts Payable')) {
                    return Http::response([
                        'QueryResponse' => ['Account' => ['Id' => '33', 'Name' => 'AP', 'AccountType' => 'Accounts Payable']],
                    ]);
                }
                if (str_contains($query, 'from Vendor')) {
                    return Http::response([
                        'QueryResponse' => ['Vendor' => ['Id' => '77', 'DisplayName' => 'Acme']],
                    ]);
                }

                return Http::response(['QueryResponse' => []]);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'procurement_manager']));
        $response = $this->postJson('/api/quickbooks/sync-po/'.$mrf->id);

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('data.passed', true)
            ->assertJsonMissingPath('data.payload')
            ->assertJsonMissingPath('payload');
        $this->assertNull($mrf->fresh()->quickbooks_po_id);
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), 'quickbooks.api.intuit.com');
        });
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'quickbooks.api.intuit.com')) {
                $this->assertSame(1, substr_count($request->url(), '?'));
            }
        }
    }

    public function test_callback_route_is_public_and_connect_is_admin_only(): void
    {
        $this->fakeDiscovery();
        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'employee']));
        $this->postJson('/api/quickbooks/connect')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'admin']));
        $response = $this->postJson('/api/quickbooks/connect');
        $response->assertOk();
        $url = (string) $response->json('data.authorization_url');
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringNotContainsString('test-client-secret', $url);
        $this->assertStringNotContainsString('client_secret', $url);
    }

    private function storeConnection(int $expiresIn = 3600): void
    {
        app(QuickBooksTokenService::class)->store([
            'access_token' => $this->plainAccess,
            'refresh_token' => $this->plainRefresh,
            'expires_in' => $expiresIn,
            'x_refresh_token_expires_in' => 100 * 86400,
        ], 'realm-should-not-leak');
    }

    private function decrypted(string $key): string
    {
        $status = app(QuickBooksTokenService::class);
        $method = new \ReflectionMethod($status, 'secret');
        $method->setAccessible(true);

        return (string) $method->invoke($status, $key);
    }

    private function fakeHttp(?\Closure $callback = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($callback);
    }

    private function fakeDiscovery(): void
    {
        $this->fakeHttp(fn (Request $request) => $this->discoveryResponse($request));
    }

    private function captureLogs(): Collection
    {
        $logged = collect();
        Log::listen(function (MessageLogged $event) use ($logged) {
            $logged->push($event);
        });

        return $logged;
    }

    private function discoveryResponse(Request $request): mixed
    {
        if (str_contains($request->url(), '.well-known/')) {
            return Http::response([
                'authorization_endpoint' => 'https://appcenter.intuit.com/connect/oauth2',
                'token_endpoint' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
                'revocation_endpoint' => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
            ]);
        }

        return Http::response([], 404);
    }

    private function makeSignedPo(): MRF
    {
        $user = User::factory()->create(['supply_chain_role' => 'procurement_manager']);
        $vendor = Vendor::create([
            'vendor_id' => 'V'.substr(uniqid(), -6),
            'name' => 'Acme',
            'category' => 'goods',
            'email' => uniqid('acme').'@example.com',
            'status' => 'Active',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-QB-'.uniqid(),
            'formatted_id' => 'MRF-QB-'.uniqid(),
            'title' => 'Pipes',
            'category' => 'goods',
            'description' => 'Pipes',
            'quantity' => '2',
            'estimated_cost' => 1000,
            'po_value' => 1000,
            'currency' => 'NGN',
            'justification' => 'Needed',
            'requester_id' => $user->id,
            'requester_name' => 'Tester',
            'date' => now()->toDateString(),
            'status' => 'signed',
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
            'current_stage' => 'finance',
            'po_number' => 'PO-ACME-0001',
            'unsigned_po_url' => 'https://s3.example/unsigned.pdf',
            'signed_po_url' => 'https://s3.example/signed.pdf',
            'po_signed_at' => now(),
            'selected_vendor_id' => $vendor->id,
        ]);

        MRFItem::create([
            'mrf_id' => $mrf->id,
            'item_name' => 'Pipe',
            'quantity' => 2,
            'unit' => 'unit',
            'unit_price' => 500,
            'total_price' => 1000,
        ]);

        return $mrf->fresh(['items', 'selectedVendor']);
    }
}
