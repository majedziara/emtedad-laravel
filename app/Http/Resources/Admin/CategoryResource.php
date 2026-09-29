<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'image_url' => $this->image_path && ! $this->trashed() ? route('admin.categories.image', ['category' => $this->id]) : null,
            'translations' => $this->translations->map(fn($t) => $t->only(['locale', 'name', 'slug', 'description'])),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
