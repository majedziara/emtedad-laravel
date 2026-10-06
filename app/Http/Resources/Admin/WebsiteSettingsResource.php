<?php

namespace App\Http\Resources\Admin;

use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assets = $this->value['_images'] ?? [];

        return ['translations' => $this->translations->map(fn ($tr) => $tr->only(['locale', 'organization_name', 'address', 'seo_title', 'seo_description'])),
            'settings' => app(WebsiteSettingsService::class)->values($this->resource),
            'logo_url' => ! empty($assets['logo']) ? route('public.settings.image', ['asset' => 'logo']) : null,
            'favicon_url' => ! empty($assets['favicon']) ? route('public.settings.image', ['asset' => 'favicon']) : null,
        ];
    }
}
