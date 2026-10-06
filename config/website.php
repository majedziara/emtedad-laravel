<?php

use App\Enum\CurrencyEnum;

return [
    'settings_key' => 'website',

    'defaults' => [
        'contact_email' => null,
        'phone' => null,

        'facebook_url' => null,
        'instagram_url' => null,
        'youtube_url' => null,
        'linkedin_url' => null,
        'x_url' => null,

        'donations_enabled' => true,

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
