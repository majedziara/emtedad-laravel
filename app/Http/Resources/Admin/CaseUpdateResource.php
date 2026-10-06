<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'status' => $this->status->value, 'published_at' => $this->published_at?->toISOString(), 'image_url' => $this->image_path ? route('admin.cases.updates.image', ['case' => $this->humanitarian_case_id, 'update' => $this->id]) : null, 'translations' => $this->translations->map(fn ($tr) => $tr->only(['locale', 'title', 'body']))];
    }
}
