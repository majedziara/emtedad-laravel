<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaginatedResource extends JsonResource
{
    public function __construct($resource, private readonly string $itemResource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['items' => $this->itemResource::collection($this->resource->getCollection()), 'pagination' => [
            'current_page' => $this->currentPage(),
            'last_page' => $this->lastPage(),
            'per_page' => $this->perPage(),
            'total' => $this->total(),
        ]];
    }
}
