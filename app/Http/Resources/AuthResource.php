<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'access_token' => $this->resource['access_token'],
            'token_type' => 'Bearer',
            'expires_at' => $this->resource['expires_at']->toISOString()
        ];
    }
}
