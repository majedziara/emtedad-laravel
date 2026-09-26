<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;

class PasswordController extends ApiController
{
    public function __construct(private readonly PasswordService $service) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->sendResetLink($request->validated('email'));

        return $this->success(null, __('api.reset_link_sent'));
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->reset($request->validated());

        return $this->success(['requires_login' => true], __('api.password_reset'));
    }

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $this->service->change($request->user(), $request->validated());

        return $this->success(['requires_login' => true], __('api.password_changed'));
    }
}
