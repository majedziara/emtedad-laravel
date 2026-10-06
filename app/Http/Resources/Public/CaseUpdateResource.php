<?php

namespace App\Http\Resources\Public;

use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseUpdateResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();

        return ['id' => $this->id, 'locale' => $translation?->locale, 'title' => $translation?->title, 'body' => $translation?->body, 'published_at' => $this->published_at?->toISOString(), 'image_url' => $this->image_path ? route('public.cases.updates.image', ['publicId' => $this->humanitarianCase->public_id, 'update' => $this->id]) : null];
    }
}
