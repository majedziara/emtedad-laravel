<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'visibility' => $this->visibility->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'sort_order' => $this->sort_order,
            'download_url' => route('admin.cases.media.download', ['case' => $this->humanitarian_case_id, 'media' => $this->id]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
