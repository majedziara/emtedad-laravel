<?php

namespace App\Http\Resources\Public;

use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();

        return ['id' => $this->id, 'locale' => $translation?->locale, 'name' => $translation?->name, 'description' => $translation?->description, 'website_url' => $this->website_url, 'logo_url' => $this->logo_path ? route('public.partners.logo', ['partner' => $this->id]) : null];
    }
}
