<?php

namespace App\Http\Resources;

use App\Enum\RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system_role' => in_array($this->name, array_column(RoleEnum::cases(), 'value'), true),
            'permissions' => $this->whenLoaded('permissions', fn() => $this->permissions->pluck('name')->sort()->values())
        ];
    }
}
