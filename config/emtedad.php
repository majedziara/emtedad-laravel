<?php

return [
    'permission_guard' => 'web',
    'locales' => ['ar', 'en'],
    'default_locale' => 'ar',
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'auth' => [
        'token_minutes' => (int) env('AUTH_TOKEN_MINUTES', 60),
        'remember_token_minutes' => (int) env('AUTH_REMEMBER_TOKEN_MINUTES', 43200),
        'verification_minutes' => 10,
        'verification_resend_seconds' => 60,
        'verification_max_attempts' => 5,
    ],
];
