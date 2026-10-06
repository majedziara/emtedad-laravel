<?php

namespace App\Http\Resources\Public;

use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentSectionResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();

        return ['id' => $this->id, 'locale' => $translation?->locale, 'title' => $translation?->title, 'subtitle' => $translation?->subtitle, 'body' => $translation?->body, 'button_label' => $translation?->button_label, 'button_url' => $translation?->button_url, 'key' => $this->key, 'page' => $this->page, 'sort_order' => $this->sort_order, 'items' => $translation?->items ?? [], 'image_url' => $this->image_path ? route('public.content.image', ['contentSection' => $this->id]) : null];
    }
}
