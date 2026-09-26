<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends ApiController
{
    public function __construct(private readonly EmailVerificationService $service) {}

    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        return $this->success(new UserResource($this->service->verify($request->user(), $request->validated('code'))), __('api.email_verified'));
    }

    public function resend(Request $request): JsonResponse
    {
        $this->service->send($request->user());

        return $this->success(null, __('api.verification_sent'));
    }
}
