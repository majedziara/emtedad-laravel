<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\AuthResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $service) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->success(new AuthResource($this->service->register($request->validated())), __('api.registered'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->success(new AuthResource($this->service->login($request->validated())), __('api.logged_in'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($this->service->profile($request->user())));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        return $this->success(new UserResource($this->service->updateProfile($request->user(), $request->validated())));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->service->logout($request->user());

        return $this->success(null, __('api.logged_out'));
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->service->logoutAll($request->user());

        return $this->success(null, __('api.logged_out'));
    }
}
