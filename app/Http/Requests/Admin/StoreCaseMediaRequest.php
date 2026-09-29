<?php

namespace App\Http\Requests\Admin;

use App\Enum\MediaVisibilityEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreCaseMediaRequest extends ApiRequest
{
    public function rules(): array
    {
        $public = $this->input('visibility') === MediaVisibilityEnum::PUBLIC->value;
        $file = $public ? File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(config('emtedad.content.image_max_kb')) : File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(config('emtedad.content.document_max_kb'));

        return [
            'file' => ['required', $file],
            'visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
