<?php

return [
    'permission_guard' => 'web',
    'locales' => ['ar', 'en'],
    'default_locale' => 'ar',
    'frontend_proxy_secret' => env('FRONTEND_PROXY_SECRET'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'content' => [
        'disk' => 'emtedad_private',
        'image_disk' => 'emtedad_images',
        'image_max_kb' => 5120,
        'document_max_kb' => 10240,
        'max_media_per_case' => 30,
        'max_amount_minor' => 1000000000000,
        'max_translations' => 20,
    ],
    'auth' => [
        'token_minutes' => (int) env('AUTH_TOKEN_MINUTES', 60),
        'remember_token_minutes' => (int) env('AUTH_REMEMBER_TOKEN_MINUTES', 43200),
        'verification_minutes' => 10,
        'verification_resend_seconds' => 60,
        'verification_max_attempts' => 5,
    ],
];
