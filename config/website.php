<?php

use App\Enum\CurrencyEnum;

return [
    'settings_key' => 'website',

    'bank_currencies' => ['ILS', 'USD', 'EUR', 'JOD'],

    'defaults' => [
        'contact_email' => null,
        'phone' => null,

        'facebook_url' => null,
        'instagram_url' => null,
        'youtube_url' => null,
        'linkedin_url' => null,
        'x_url' => null,

        'donations_enabled' => true,

        'gofundme_enabled' => false,
        'gofundme_url' => null,
        'bank_transfer_enabled' => false,
        'bank_name' => null,
        'bank_beneficiary_name' => null,
        'bank_beneficiary_name_en' => null,
        'bank_swift' => null,
        'bank_iban_ils' => null,
        'bank_iban_usd' => null,
        'bank_iban_eur' => null,
        'bank_iban_jod' => null,

        'display_currencies' => array_column(
            CurrencyEnum::cases(),
            'value',
        ),
    ],

    'home' => [
        'sections' => 30,
        'partners' => 12,
        'cases' => 6,
        'categories' => 12,
    ],
];
