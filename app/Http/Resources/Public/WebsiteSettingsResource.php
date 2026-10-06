<?php

namespace App\Http\Resources\Public;

use App\Services\WebsiteSettingsService;
use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteSettingsResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();
        $assets = $this->value['_images'] ?? [];

        return ['locale' => $translation?->locale, 'organization_name' => $translation?->organization_name, 'address' => $translation?->address, 'seo_title' => $translation?->seo_title, 'seo_description' => $translation?->seo_description, 'available_locales' => config('emtedad.locales'), 'default_locale' => config('emtedad.default_locale'), 'payment_environment' => config('paypal.mode'),
            'settings' => app(WebsiteSettingsService::class)->values($this->resource),
            'logo_url' => ! empty($assets['logo']) ? route('public.settings.image', ['asset' => 'logo']) : null,
            'favicon_url' => ! empty($assets['favicon']) ? route('public.settings.image', ['asset' => 'favicon']) : null,
        ];
    }
}
