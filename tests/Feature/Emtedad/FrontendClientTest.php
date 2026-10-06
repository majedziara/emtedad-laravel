<?php

namespace Tests\Feature\Emtedad;

use App\Http\Middleware\VerifyFrontendClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class FrontendClientTest extends EmtedadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['emtedad.frontend_proxy_secret' => str_repeat('a', 64)]);
        Route::middleware('api')->get('/api/v1/client-probe', fn (Request $request) => response()->json([
            'client' => $request->attributes->get(VerifyFrontendClient::ATTRIBUTE),
            'peer' => $request->ip(),
        ]));
    }

    private function signed(string $ip, ?int $timestamp = null): array
    {
        $time = (string) ($timestamp ?? now()->timestamp);

        return [
            'X-Emtedad-Client-IP' => $ip,
            'X-Emtedad-Client-Time' => $time,
            'X-Emtedad-Client-Signature' => hash_hmac('sha256', $time.':'.$ip, str_repeat('a', 64)),
        ];
    }

    public function test_verified_ipv4_and_ipv6_are_available_without_changing_the_network_peer(): void
    {
        foreach (['203.0.113.10', '2001:db8::10'] as $ip) {
            $this->getJson('/api/v1/client-probe', $this->signed($ip))->assertOk()
                ->assertJsonPath('client', $ip)->assertJsonPath('peer', '127.0.0.1');
        }
    }

    public function test_missing_forged_expired_future_and_malformed_headers_are_not_trusted(): void
    {
        $invalid = [
            ['X-Emtedad-Client-IP' => '203.0.113.10', 'X-Forwarded-For' => '203.0.113.10'],
            array_replace($this->signed('203.0.113.10'), ['X-Emtedad-Client-Signature' => str_repeat('b', 64)]),
            $this->signed('203.0.113.10', now()->timestamp - 120),
            $this->signed('203.0.113.10', now()->timestamp + 120),
            $this->signed('203.0.113.10, 203.0.113.20'),
        ];
        foreach ($invalid as $headers) {
            $this->getJson('/api/v1/client-probe', $headers)->assertOk()->assertJsonPath('client', null);
        }
        config(['emtedad.frontend_proxy_secret' => null]);
        $this->getJson('/api/v1/client-probe', $this->signed('203.0.113.10'))->assertOk()->assertJsonPath('client', null);
    }

    public function test_login_limits_are_separate_for_verified_visitors_and_still_enforced(): void
    {
        $input = ['email' => 'missing@example.test', 'password' => 'StrongPassword123'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $input, $this->signed('203.0.113.10'))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', $input, $this->signed('203.0.113.10'))->assertStatus(429);
        $this->postJson('/api/v1/auth/login', $input, $this->signed('203.0.113.20'))->assertUnprocessable();
    }
}
