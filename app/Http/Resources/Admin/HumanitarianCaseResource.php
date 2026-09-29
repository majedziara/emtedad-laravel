<?php

namespace App\Http\Resources\Admin;

use App\Enum\PermissionEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HumanitarianCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'category_id' => $this->category_id,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'target_amount_minor' => $this->target_amount_minor,
            'currency' => $this->currency->value,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'is_featured' => $this->is_featured,
            'beneficiaries_count' => $this->beneficiaries_count,
            'country_code' => $this->country_code,
            'beneficiary_reference' => $this->when($request->user()->can(PermissionEnum::CASES_DOCUMENTS_VIEW->value), $this->beneficiary_reference),
            'cover_url' => $this->cover_image_path && ! $this->trashed() ? route('admin.cases.cover', ['case' => $this->id]) : null,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'translations' => $this->translations->map(fn($t) => $t->only(['locale', 'title', 'slug', 'summary', 'story', 'location', 'meta_title', 'meta_description'])),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'deleted_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
