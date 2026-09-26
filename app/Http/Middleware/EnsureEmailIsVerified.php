<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasVerifiedEmail(), 403, __('api.email_not_verified'));

        return $next($request);
    }
}
