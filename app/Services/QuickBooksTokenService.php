<?php

namespace App\Services;

use App\Exceptions\QuickBooksException;
use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuickBooksTokenService
{
    public const ACCESS_TOKEN = 'qb_access_token';

    public const REFRESH_TOKEN = 'qb_refresh_token';

    public const REALM_ID = 'qb_realm_id';

    public const ACCESS_EXPIRES_AT = 'qb_access_token_expires_at';

    public const REFRESH_EXPIRES_AT = 'qb_refresh_token_expires_at';

    public const CONNECTED_AT = 'qb_connected_at';

    public const NEEDS_RECONNECT = 'qb_needs_reconnect';

    private const REFRESH_TTL_SECONDS = 8640000;

    public function encryptionKeyIsValid(): bool
    {
        try {
            $encrypter = $this->encrypter();
            $cipher = $encrypter->encrypt('ok');

            return $encrypter->decrypt($cipher) === 'ok' && $cipher !== 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *     connected: bool,
     *     needs_reconnect: bool,
     *     connected_at: ?string,
     *     access_token_expires_at: ?string,
     *     refresh_token_expires_at: ?string
     * }
     */
    public function getConnectionStatus(): array
    {
        $empty = [
            'connected' => false,
            'needs_reconnect' => false,
            'connected_at' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ];

        if (! $this->encryptionKeyIsValid()) {
            $empty['needs_reconnect'] = $this->hasStoredSecrets();

            return $empty;
        }

        $access = $this->secret(self::ACCESS_TOKEN);
        $refresh = $this->secret(self::REFRESH_TOKEN);
        $realm = $this->secret(self::REALM_ID);
        $decryptFailed = $this->hasStoredSecrets() && ($access === null || $refresh === null || $realm === null);

        $status = [
            'connected' => false,
            'needs_reconnect' => $decryptFailed || $this->plain(self::NEEDS_RECONNECT) === '1',
            'connected_at' => $this->plain(self::CONNECTED_AT),
            'access_token_expires_at' => $this->plain(self::ACCESS_EXPIRES_AT),
            'refresh_token_expires_at' => $this->plain(self::REFRESH_EXPIRES_AT),
        ];

        if ($decryptFailed || $access === null || $refresh === null || $realm === null) {
            return $status;
        }

        if ($this->isRefreshTokenExpired()) {
            $status['needs_reconnect'] = true;

            return $status;
        }

        $status['connected'] = ! $status['needs_reconnect'];

        return $status;
    }

    public function isAccessTokenExpired(): bool
    {
        $expires = $this->plain(self::ACCESS_EXPIRES_AT);
        if ($expires === null) {
            return true;
        }

        try {
            return now()->gte(Carbon::parse($expires)->subMinutes(5));
        } catch (\Throwable) {
            return true;
        }
    }

    public function isRefreshTokenExpired(): bool
    {
        $expires = $this->plain(self::REFRESH_EXPIRES_AT);
        if ($expires === null) {
            return true;
        }

        try {
            return now()->gte(Carbon::parse($expires));
        } catch (\Throwable) {
            return true;
        }
    }

    public function accessToken(): string
    {
        if ($this->isAccessTokenExpired()) {
            $this->refresh(false);
        }

        $token = $this->secret(self::ACCESS_TOKEN);
        if ($token === null || $token === '') {
            throw new QuickBooksException('QuickBooks is not connected.', status: 409);
        }

        return $token;
    }

    public function realmId(): string
    {
        $realm = $this->secret(self::REALM_ID);
        if ($realm === null || $realm === '') {
            throw new QuickBooksException('QuickBooks is not connected.', status: 409);
        }

        return $realm;
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     */
    public function store(array $tokenResponse, string $realmId): void
    {
        $access = isset($tokenResponse['access_token']) ? (string) $tokenResponse['access_token'] : '';
        $refresh = isset($tokenResponse['refresh_token']) ? (string) $tokenResponse['refresh_token'] : '';
        if ($access === '' || $realmId === '') {
            throw new QuickBooksException('QuickBooks token response was incomplete.', status: 502);
        }

        $now = now();
        $accessTtl = max(1, (int) ($tokenResponse['expires_in'] ?? 3600));
        $this->writeSecret(self::ACCESS_TOKEN, $access);
        $this->writeSecret(self::REALM_ID, $realmId);
        $this->writePlain(self::ACCESS_EXPIRES_AT, $now->copy()->addSeconds($accessTtl)->toIso8601String());

        if ($refresh !== '') {
            $refreshTtl = (int) ($tokenResponse['x_refresh_token_expires_in'] ?? self::REFRESH_TTL_SECONDS);
            if ($refreshTtl < 1) {
                $refreshTtl = self::REFRESH_TTL_SECONDS;
            }
            $this->writeSecret(self::REFRESH_TOKEN, $refresh);
            $this->writePlain(self::REFRESH_EXPIRES_AT, $now->copy()->addSeconds($refreshTtl)->toIso8601String());
        }

        if ($this->plain(self::CONNECTED_AT) === null) {
            $this->writePlain(self::CONNECTED_AT, $now->toIso8601String());
        }

        $this->delete(self::NEEDS_RECONNECT);
    }

    public function exchangeAuthorizationCode(string $code, string $realmId): void
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) config('services.quickbooks.redirect_uri'),
        ], 'token_exchange');

        $this->store($response, $realmId);
    }

    public function refresh(bool $force = false): void
    {
        $snapshot = $this->raw(self::ACCESS_TOKEN);
        $lock = Cache::lock('qb_token_refresh', 15);

        try {
            $lock->block(5, function () use ($force, $snapshot) {
                $current = $this->raw(self::ACCESS_TOKEN);
                if ($current !== $snapshot && ! $this->isAccessTokenExpired()) {
                    return;
                }
                if (! $force && ! $this->isAccessTokenExpired()) {
                    return;
                }
                if ($this->isRefreshTokenExpired()) {
                    $this->writePlain(self::NEEDS_RECONNECT, '1');
                    throw new QuickBooksException('The QuickBooks connection has expired. Reconnect QuickBooks.', status: 409);
                }

                $refresh = $this->secret(self::REFRESH_TOKEN);
                $realm = $this->secret(self::REALM_ID);
                if ($refresh === null || $realm === null) {
                    $this->writePlain(self::NEEDS_RECONNECT, '1');
                    throw new QuickBooksException('QuickBooks is not connected.', status: 409);
                }

                $response = $this->tokenRequest([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                ], 'token_refresh');

                $this->store($response, $realm);
            });
        } catch (QuickBooksException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new QuickBooksException('QuickBooks token refresh could not be completed.', status: 409);
        }
    }

    public function disconnect(QuickBooksDiscoveryService $discovery): void
    {
        $refresh = null;
        try {
            $refresh = $this->secret(self::REFRESH_TOKEN);
        } catch (\Throwable) {
            $refresh = null;
        }

        if (is_string($refresh) && $refresh !== '') {
            try {
                $response = Http::asForm()
                    ->withBasicAuth($this->clientId(), $this->clientSecret())
                    ->timeout(15)
                    ->post($discovery->revocationEndpoint(), [
                        'token' => $refresh,
                    ]);

                if (! $response->successful()) {
                    $this->logFailure('revoke', $response->status(), $response->header('intuit_tid'));
                }
            } catch (ConnectionException) {
                $this->logFailure('revoke', null, null);
            } catch (\Throwable) {
                $this->logFailure('revoke', null, null);
            }
        }

        SystemSetting::query()
            ->where('category', SystemSetting::CATEGORY_QUICKBOOKS)
            ->delete();
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function tokenRequest(array $form, string $operation): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->timeout(20)
                ->post(app(QuickBooksDiscoveryService::class)->tokenEndpoint(), $form);
        } catch (ConnectionException) {
            $this->logFailure($operation, null, null);
            throw new QuickBooksException('QuickBooks could not be reached.', status: 502);
        }

        if (! $response->successful()) {
            $this->logFailure($operation, $response->status(), $response->header('intuit_tid'));
            $this->writePlain(self::NEEDS_RECONNECT, '1');
            throw new QuickBooksException('QuickBooks token request failed.', $response->status(), null, $this->header($response->header('intuit_tid')), 502);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function encrypter(): Encrypter
    {
        $key = (string) config('services.quickbooks.encryption_key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new \RuntimeException('QUICKBOOKS_ENCRYPTION_KEY is not valid base64.');
            }
            $key = $decoded;
        }

        if (! Encrypter::supported($key, 'aes-256-cbc')) {
            throw new \RuntimeException('QUICKBOOKS_ENCRYPTION_KEY must be a 32-byte AES-256 key.');
        }

        return new Encrypter($key, 'aes-256-cbc');
    }

    private function writeSecret(string $key, string $value): void
    {
        $this->writePlain($key, $this->encrypter()->encrypt($value));
    }

    private function secret(string $key): ?string
    {
        $stored = $this->raw($key);
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $value = $this->encrypter()->decrypt($stored);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (DecryptException) {
            return null;
        }
    }

    private function writePlain(string $key, string $value): void
    {
        SystemSetting::query()->updateOrCreate(
            [
                'category' => SystemSetting::CATEGORY_QUICKBOOKS,
                'key' => $key,
            ],
            ['value' => $value],
        );
    }

    private function plain(string $key): ?string
    {
        $value = $this->raw($key);

        return $value === null || $value === '' ? null : $value;
    }

    private function raw(string $key): ?string
    {
        $value = SystemSetting::query()
            ->where('category', SystemSetting::CATEGORY_QUICKBOOKS)
            ->where('key', $key)
            ->value('value');

        return is_string($value) ? $value : null;
    }

    private function delete(string $key): void
    {
        SystemSetting::query()
            ->where('category', SystemSetting::CATEGORY_QUICKBOOKS)
            ->where('key', $key)
            ->delete();
    }

    private function hasStoredSecrets(): bool
    {
        return SystemSetting::query()
            ->where('category', SystemSetting::CATEGORY_QUICKBOOKS)
            ->whereIn('key', [self::ACCESS_TOKEN, self::REFRESH_TOKEN, self::REALM_ID])
            ->exists();
    }

    private function clientId(): string
    {
        $id = (string) config('services.quickbooks.client_id');
        if ($id === '') {
            throw new QuickBooksException('QUICKBOOKS_CLIENT_ID is not configured.', status: 500);
        }

        return $id;
    }

    private function clientSecret(): string
    {
        $secret = (string) config('services.quickbooks.client_secret');
        if ($secret === '') {
            throw new QuickBooksException('QUICKBOOKS_CLIENT_SECRET is not configured.', status: 500);
        }

        return $secret;
    }

    private function logFailure(string $operation, ?int $status, mixed $intuitTid): void
    {
        Log::warning('QuickBooks request failed', [
            'operation' => $operation,
            'mrf_id' => null,
            'http_status' => $status,
            'fault_code' => null,
            'intuit_tid' => $this->header($intuitTid),
        ]);
    }

    private function header(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
