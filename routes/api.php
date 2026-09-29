<?php

use App\Enum\PermissionEnum;
use App\Enum\RoleEnum;
use App\Http\Controllers\Admin\CaseMediaController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DonationReportController;
use App\Http\Controllers\Admin\HumanitarianCaseController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\PayPalReturnController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\Public\CatalogController;
use App\Models\HumanitarianCase;
use Illuminate\Support\Facades\Route;

Route::model('case', HumanitarianCase::class);
// Outside the visitor throttle and Sanctum group: authenticated by PayPal signature.
Route::post('v1/webhooks/paypal', PayPalWebhookController::class)->name('paypal.webhook');
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('payments/paypal/return', [PayPalReturnController::class, 'returned'])->name('paypal.return');
    Route::get('payments/paypal/cancel', [PayPalReturnController::class, 'cancelled'])->name('paypal.cancel');
    Route::prefix('guest/donations')->name('guest.donations.')->middleware('throttle:checkout')->group(function () {
        Route::post('paypal', [DonationController::class, 'storeGuest'])->name('store');
        Route::get('{publicId}', [DonationController::class, 'showGuest'])->whereUuid('publicId')->name('show');
        Route::post('{publicId}/paypal/capture', [DonationController::class, 'captureGuest'])->whereUuid('publicId')->name('capture');
    });
    Route::prefix('donations')->name('donations.')->middleware(['auth:sanctum', 'active', 'verified', 'throttle:checkout'])->group(function () {
        Route::post('paypal', [DonationController::class, 'store'])->name('store');
        Route::get('/', [DonationController::class, 'index'])->name('index');
        Route::get('{publicId}', [DonationController::class, 'show'])->whereUuid('publicId')->name('show');
        Route::post('{publicId}/paypal/capture', [DonationController::class, 'capture'])->whereUuid('publicId')->name('capture');
    });
    Route::prefix('public')->name('public.')->group(function () {
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
        Route::get('categories/{category}', [CatalogController::class, 'category'])->whereNumber('category')->name('categories.show');
        Route::get('categories/{category}/image', [CatalogController::class, 'categoryImage'])->whereNumber('category')->name('categories.image');
        Route::get('cases', [CatalogController::class, 'index'])->name('cases.index');
        Route::get('cases/by-slug/{slug}', [CatalogController::class, 'bySlug'])->name('cases.by-slug');
        Route::get('cases/{publicId}', [CatalogController::class, 'show'])->whereUuid('publicId')->name('cases.show');
        Route::get('cases/{publicId}/cover', [CatalogController::class, 'cover'])->whereUuid('publicId')->name('cases.cover');
        Route::get('cases/{publicId}/media/{media}', [CatalogController::class, 'media'])->whereUuid('publicId')->whereNumber('media')->name('cases.media');
    });
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
        Route::name('admin.')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index'])
                ->middleware('permission:' . PermissionEnum::DASHBOARD_VIEW->value)->name('dashboard');
            Route::get('dashboard/donations', [DashboardController::class, 'donations'])
                ->middleware(['permission:' . PermissionEnum::DASHBOARD_VIEW->value, 'permission:' . PermissionEnum::DONATIONS_VIEW->value])->name('dashboard.donations');
            Route::prefix('reports')->name('reports.')->middleware(['permission:' . PermissionEnum::DONATIONS_VIEW->value, 'throttle:reports'])->group(function () {
                Route::get('donations/export', [DonationReportController::class, 'export'])
                    ->middleware(['permission:' . PermissionEnum::DONATIONS_EXPORT->value, 'throttle:report-exports'])->name('donations.export');
                Route::get('donations', [DonationReportController::class, 'index'])->name('donations');
                Route::get('cases', [DonationReportController::class, 'cases'])->name('cases');
            });
            Route::middleware('permission:' . PermissionEnum::DONATIONS_VIEW->value)->group(function () {
                Route::get('donations', [DonationController::class, 'adminIndex'])->name('donations.index');
                Route::get('donations/{publicId}', [DonationController::class, 'adminShow'])->whereUuid('publicId')->name('donations.show');
            });
            Route::middleware('permission:' . PermissionEnum::CASES_VIEW->value . '|' . PermissionEnum::CATEGORIES_MANAGE->value)->group(function () {
                Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
                Route::get('categories/{category}', [CategoryController::class, 'show'])->whereNumber('category')->name('categories.show');
                Route::get('categories/{category}/image', [CategoryController::class, 'image'])->whereNumber('category')->name('categories.image');
            });
            Route::middleware('permission:' . PermissionEnum::CATEGORIES_MANAGE->value)->group(function () {
                Route::post('categories', [CategoryController::class, 'store']);
                Route::patch('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
                Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
                Route::post('categories/{id}/restore', [CategoryController::class, 'restore'])->whereNumber('id');
                Route::delete('categories/{category}/translations/{locale}', [CategoryController::class, 'deleteTranslation'])->whereNumber('category');
                Route::post('categories/{category}/image', [CategoryController::class, 'uploadImage'])->whereNumber('category');
                Route::delete('categories/{category}/image', [CategoryController::class, 'deleteImage'])->whereNumber('category');
            });
            Route::middleware('permission:' . PermissionEnum::CASES_VIEW->value)->group(function () {
                Route::get('cases', [HumanitarianCaseController::class, 'index']);
                Route::get('cases/{case}', [HumanitarianCaseController::class, 'show'])->whereNumber('case');
                Route::get('cases/{case}/cover', [HumanitarianCaseController::class, 'cover'])->whereNumber('case')->name('cases.cover');
                Route::get('cases/{case}/media', [CaseMediaController::class, 'index'])->whereNumber('case');
                Route::get('cases/{case}/media/{media}/download', [CaseMediaController::class, 'download'])->whereNumber(['case', 'media'])->name('cases.media.download');
            });
            Route::post('cases', [HumanitarianCaseController::class, 'store'])->middleware('permission:' . PermissionEnum::CASES_CREATE->value);
            Route::middleware('permission:' . PermissionEnum::CASES_UPDATE->value)->group(function () {
                Route::patch('cases/{case}', [HumanitarianCaseController::class, 'update'])->whereNumber('case');
                Route::delete('cases/{case}/translations/{locale}', [HumanitarianCaseController::class, 'deleteTranslation'])->whereNumber('case');
                Route::post('cases/{case}/cover', [HumanitarianCaseController::class, 'uploadCover'])->whereNumber('case');
                Route::delete('cases/{case}/cover', [HumanitarianCaseController::class, 'deleteCover'])->whereNumber('case');
                Route::post('cases/{case}/media', [CaseMediaController::class, 'store'])->whereNumber('case');
                Route::patch('cases/{case}/media/{media}', [CaseMediaController::class, 'update'])->whereNumber(['case', 'media']);
                Route::delete('cases/{case}/media/{media}', [CaseMediaController::class, 'destroy'])->whereNumber(['case', 'media']);
            });
            Route::patch('cases/{case}/status', [HumanitarianCaseController::class, 'status'])->whereNumber('case')->middleware('permission:' . PermissionEnum::CASES_PUBLISH->value);
            Route::delete('cases/{case}', [HumanitarianCaseController::class, 'destroy'])->whereNumber('case')->middleware('permission:' . PermissionEnum::CASES_ARCHIVE->value);
            Route::post('cases/{id}/restore', [HumanitarianCaseController::class, 'restore'])->whereNumber('id')->middleware('permission:' . PermissionEnum::CASES_ARCHIVE->value);
        });
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
