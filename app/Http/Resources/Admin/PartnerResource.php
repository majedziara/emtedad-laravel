<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'website_url' => $this->website_url, 'is_active' => $this->is_active, 'sort_order' => $this->sort_order, 'logo_url' => $this->logo_path ? route('admin.partners.logo', ['partner' => $this->id]) : null, 'translations' => $this->translations->map(fn ($tr) => $tr->only(['locale', 'name', 'description']))];
    }
}
