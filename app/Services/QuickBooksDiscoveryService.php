<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class QuickBooksDiscoveryService
{
    public ?int $lastHttpStatus = null;

    public function reachable(): bool
    {
        return $this->fetch(true) !== null;
    }

    public function authorizationEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint')
            ?? $this->fallback((string) config('services.quickbooks.auth_url'), 'https://appcenter.intuit.com/connect/oauth2');
    }

    public function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint')
            ?? $this->fallback((string) config('services.quickbooks.token_url'), 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
    }

    public function revocationEndpoint(): string
    {
        return $this->endpoint('revocation_endpoint')
            ?? 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
    }

    public function apiBaseUrl(): string
    {
        $environment = (string) config('services.quickbooks.environment');
        if ($environment === 'sandbox') {
            return 'https://sandbox-quickbooks.api.intuit.com';
        }
        if ($environment === 'production') {
            return 'https://quickbooks.api.intuit.com';
        }

        $fallback = trim((string) config('services.quickbooks.base_url'));

        return $fallback !== '' ? rtrim($fallback, '/') : 'https://quickbooks.api.intuit.com';
    }

    public function discoveryUrl(): string
    {
        if ((string) config('services.quickbooks.environment') === 'sandbox') {
            return 'https://developer.api.intuit.com/.well-known/openid_sandbox_configuration';
        }

        return 'https://developer.api.intuit.com/.well-known/openid_configuration';
    }

    private function endpoint(string $key): ?string
    {
        $document = $this->fetch(false);
        $value = is_array($document) ? ($document[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function fetch(bool $force): ?array
    {
        $cacheKey = 'qb_openid_configuration_'.((string) config('services.quickbooks.environment'));
        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['token_endpoint'])) {
                return $cached;
            }
        }

        $this->lastHttpStatus = null;

        try {
            $response = Http::timeout(10)->acceptJson()->get($this->discoveryUrl());
        } catch (ConnectionException) {
            return null;
        }

        $this->lastHttpStatus = $response->status();
        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ! is_string($json['token_endpoint'] ?? null)) {
            return null;
        }

        $document = array_filter([
            'authorization_endpoint' => $json['authorization_endpoint'] ?? null,
            'token_endpoint' => $json['token_endpoint'] ?? null,
            'revocation_endpoint' => $json['revocation_endpoint'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        Cache::put($cacheKey, $document, now()->addHours(24));

        return $document;
    }

    private function fallback(string $configured, string $default): string
    {
        $configured = trim($configured);

        return $configured !== '' ? $configured : $default;
    }
}
