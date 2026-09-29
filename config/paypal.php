<?php

return [
    'mode' => env('PAYPAL_MODE', 'sandbox'),
    'client_id' => env('PAYPAL_CLIENT_ID'),
    'client_secret' => env('PAYPAL_CLIENT_SECRET'),
    'merchant_id' => env('PAYPAL_MERCHANT_ID'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    'return_url' => env('PAYPAL_RETURN_URL'),
    'cancel_url' => env('PAYPAL_CANCEL_URL'),
    'brand_name' => 'Emtedad Charity Association',
    'currencies' => ['USD', 'EUR', 'ILS'],
    'min_amount_minor' => 100,
    'max_amount_minor' => 1000000,
    'timeout_seconds' => 20,
    'lock_seconds' => 300,
    'create_retry_minutes' => 300,
    'queue' => 'payments',
];
