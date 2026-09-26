<?php

namespace App\Http\Middleware;

use App\Enum\UserStatusEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->status === UserStatusEnum::ACTIVE, 403, __('api.account_suspended'));

        return $next($request);
    }
}
