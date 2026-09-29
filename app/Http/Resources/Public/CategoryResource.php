<?php

namespace App\Http\Resources\Public;

use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();

        return [
            'id' => $this->id,
            'locale' => $translation?->locale,
            'name' => $translation?->name,
            'slug' => $translation?->slug,
            'description' => $translation?->description,
            'sort_order' => $this->sort_order,
            'image_url' => $this->image_path ? route('public.categories.image', ['category' => $this->id]) : null,
        ];
    }
}
