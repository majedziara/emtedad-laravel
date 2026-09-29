<?php

namespace App\Http\Requests\Admin;

use App\Enum\MediaVisibilityEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateCaseMediaRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'visibility' => ['sometimes', Rule::enum(MediaVisibilityEnum::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'path' => ['prohibited'],
            'disk' => ['prohibited'],
            'type' => ['prohibited'],
            'file' => ['prohibited'],
        ];
    }
}
