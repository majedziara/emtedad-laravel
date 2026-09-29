<?php

namespace App\Services;

use App\Exceptions\PayPalException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalService
{
    public function baseUrl(): string
    {
        return match (config('paypal.mode')) {
            'sandbox' => 'https://api-m.sandbox.paypal.com',
            'live' => 'https://api-m.paypal.com',
            default => throw new PayPalException('INVALID_PAYPAL_MODE'),
        };
    }

    public function assertConfigured(bool $checkout = false): void
    {
        $this->baseUrl();
        $keys = $checkout ? ['client_id', 'client_secret', 'merchant_id', 'return_url', 'cancel_url'] : ['client_id', 'client_secret'];
        foreach ($keys as $key) {
            if (! is_string(config("paypal.{$key}")) || trim(config("paypal.{$key}")) === '') {
                throw new PayPalException('PAYPAL_NOT_CONFIGURED');
            }
        }
        if ($checkout) {
            foreach (['return_url', 'cancel_url'] as $key) {
                $url = config("paypal.{$key}");
                if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new PayPalException('PAYPAL_CALLBACK_MUST_USE_HTTPS');
                }
            }
        }
    }

    public function accessToken(bool $refresh = false): string
    {
        $this->assertConfigured();
        $key = 'paypal:token:' . hash('sha256', $this->baseUrl() . config('paypal.client_id') . config('paypal.client_secret'));
        if (! $refresh && is_string($token = Cache::get($key))) {
            return $token;
        }
        try {
            $response = Http::asForm()->acceptJson()->withoutRedirecting()->withBasicAuth(config('paypal.client_id'), config('paypal.client_secret'))->connectTimeout(5)->timeout(config('paypal.timeout_seconds'))->post($this->baseUrl() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new PayPalException('PAYPAL_CONNECTION_FAILED');
        }
        $data = $this->json($response);
        if (! is_string($data['access_token'] ?? null) || (int) ($data['expires_in'] ?? 0) < 120) {
            throw new PayPalException('INVALID_OAUTH_RESPONSE');
        }
        Cache::put($key, $data['access_token'], max(60, (int) $data['expires_in'] - 60));

        return $data['access_token'];
    }

    public function createOrder(array $payload, string $requestId): array
    {
        return $this->request('POST', '/v2/checkout/orders', $payload, $requestId);
    }

    public function order(string $id): array
    {
        return $this->request('GET', '/v2/checkout/orders/' . rawurlencode($id));
    }

    public function captureOrder(string $id, string $requestId): array
    {
        return $this->request('POST', '/v2/checkout/orders/' . rawurlencode($id) . '/capture', [], $requestId);
    }

    public function capture(string $id): array
    {
        return $this->request('GET', '/v2/payments/captures/' . rawurlencode($id));
    }

    public function refund(string $id): array
    {
        return $this->request('GET', '/v2/payments/refunds/' . rawurlencode($id));
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $webhookId = config('paypal.webhook_id');
        if (! is_string($webhookId) || $webhookId === '') {
            throw new PayPalException('WEBHOOK_ID_MISSING');
        }
        $hosts = config('paypal.mode') === 'sandbox' ? ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'] : ['api.paypal.com', 'api-m.paypal.com'];
        $certUrl = $headers['cert_url'] ?? '';
        if (! in_array(parse_url($certUrl, PHP_URL_HOST), $hosts, true) || parse_url($certUrl, PHP_URL_SCHEME) !== 'https' || ! str_starts_with((string) parse_url($certUrl, PHP_URL_PATH), '/v1/notifications/certs/')) {
            return false;
        }
        // Embed the original JSON unchanged. Laravel's parsed input may be trimmed.
        $prefix = json_encode($headers + ['webhook_id' => $webhookId], JSON_THROW_ON_ERROR);
        $body = substr($prefix, 0, -1) . ',"webhook_event":' . $rawBody . '}';
        $data = $this->request('POST', '/v1/notifications/verify-webhook-signature', rawBody: $body);

        return ($data['verification_status'] ?? null) === 'SUCCESS';
    }

    private function request(string $method, string $path, array $payload = [], ?string $requestId = null, ?string $rawBody = null): array
    {
        $token = $this->accessToken();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $request = Http::withToken($token)->acceptJson()->withoutRedirecting()->withHeaders(['Prefer' => 'return=representation'])->connectTimeout(5)->timeout(config('paypal.timeout_seconds'));
            if ($requestId) {
                $request = $request->withHeaders(['PayPal-Request-Id' => $requestId]);
            }
            $options = [];
            if ($method !== 'GET') {
                $request = $request->withBody($rawBody ?? json_encode($payload ?: new \stdClass, JSON_THROW_ON_ERROR), 'application/json');
            }
            try {
                $response = $request->send($method, $this->baseUrl() . $path, $options);
            } catch (ConnectionException) {
                throw new PayPalException('PAYPAL_CONNECTION_FAILED');
            }
            if ($response->status() !== 401 || $attempt === 1) {
                return $this->json($response);
            }
            $token = $this->accessToken(true);
        }
        throw new PayPalException('PAYPAL_AUTH_FAILED');
    }

    private function json(Response $response): array
    {
        if (! $response->successful()) {
            $reason = $response->json('details.0.issue') ?? $response->json('name') ?? 'PAYPAL_UNAVAILABLE';
            $reason = is_string($reason) && preg_match('/\A[A-Z0-9_]{1,100}\z/', $reason) ? $reason : 'PAYPAL_UNAVAILABLE';
            throw new PayPalException($reason, $response->status() === 422 ? 422 : 503);
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new PayPalException('INVALID_PAYPAL_RESPONSE');
        }

        return $body;
    }
}
