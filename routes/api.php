<?php

use App\Enum\PermissionEnum;
use App\Enum\RoleEnum;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:auth-password');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:auth-password');
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::patch('profile', [AuthController::class, 'updateProfile']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::post('verify-email', [EmailVerificationController::class, 'verify'])->middleware('throttle:auth-verify');
            Route::post('resend-verification', [EmailVerificationController::class, 'resend'])->middleware('throttle:auth-resend');
            Route::patch('password', [PasswordController::class, 'change'])->middleware(['verified', 'throttle:auth-password']);
        });
    });
    Route::prefix('admin')->middleware(['auth:sanctum', 'active', 'verified'])->group(function () {
        Route::get('access', [AuthController::class, 'me'])->middleware('permission:' . PermissionEnum::DASHBOARD_VIEW->value);
        Route::middleware(['role:' . RoleEnum::ADMIN->value, 'permission:' . PermissionEnum::USERS_MANAGE->value])->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::patch('users/{user}/roles', [UserController::class, 'syncRoles']);
        });
        Route::middleware(['role:' . RoleEnum::ADMIN->value, 'permission:' . PermissionEnum::ROLES_MANAGE->value])->group(function () {
            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::apiResource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
        });
    });
});
