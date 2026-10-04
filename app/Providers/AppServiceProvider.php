<?php

namespace App\Providers;

use App\Http\Middleware\VerifyFrontendClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Password::defaults(fn() => Password::min(12)->mixedCase()->numbers());
        RateLimiter::for('reports', fn(Request $r) => Limit::perMinute(30)->by($r->user()->id));
        RateLimiter::for('report-exports', fn(Request $r) => Limit::perMinute(3)->by($r->user()->id));
        RateLimiter::for('checkout', fn(Request $r) => Limit::perMinute(20)->by($r->user()?->id ? 'user:' . $r->user()->id : 'ip:' . $this->clientIp($r)));
        RateLimiter::for('api', fn(Request $r) => Limit::perMinute(120)->by($this->clientIp($r)));
        RateLimiter::for('auth-login', fn(Request $r) => [Limit::perMinute(30)->by('ip:' . $this->clientIp($r)), Limit::perMinute(5)->by('email:' . hash('sha256', Str::lower(trim(is_string($r->input('email')) ? $r->input('email') : ''))) . '|' . $this->clientIp($r))]);
        RateLimiter::for('auth-register', fn(Request $r) => Limit::perHour(5)->by($this->clientIp($r)));
        RateLimiter::for('auth-password', fn(Request $r) => Limit::perMinute(5)->by($this->clientIp($r)));
        RateLimiter::for('auth-verify', fn(Request $r) => Limit::perMinute(10)->by($r->user()->id));
        RateLimiter::for('auth-resend', fn(Request $r) => [Limit::perMinute(1)->by('minute:' . $r->user()->id), Limit::perHour(5)->by('hour:' . $r->user()->id)]);
    }

    private function clientIp(Request $request): string
    {
        return $request->attributes->get(VerifyFrontendClient::ATTRIBUTE, $request->ip());
    }
}
