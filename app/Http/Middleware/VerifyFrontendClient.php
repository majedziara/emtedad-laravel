<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFrontendClient
{
    public const ATTRIBUTE = 'emtedad.client_ip';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('emtedad.frontend_proxy_secret');
        $ip = $request->header('X-Emtedad-Client-IP', '');
        $time = $request->header('X-Emtedad-Client-Time', '');
        $signature = $request->header('X-Emtedad-Client-Signature', '');

        if (is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/i', $secret)
            && filter_var($ip, FILTER_VALIDATE_IP)
            && preg_match('/\A[0-9]{10,11}\z/', $time)
            && abs(now()->timestamp - (int) $time) <= 60
            && preg_match('/\A[a-f0-9]{64}\z/', $signature)
            && hash_equals(hash_hmac('sha256', $time.':'.$ip, $secret), $signature)) {
            $request->attributes->set(self::ATTRIBUTE, $ip);
        }

        return $next($request);
    }
}
