<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'key' => $this->key, 'page' => $this->page, 'is_active' => $this->is_active, 'sort_order' => $this->sort_order, 'image_url' => $this->image_path ? route('admin.content-sections.image', ['contentSection' => $this->id]) : null, 'translations' => $this->translations->map(fn ($tr) => $tr->only(['locale', 'title', 'subtitle', 'body', 'button_label', 'button_url', 'items']))];
    }
}
