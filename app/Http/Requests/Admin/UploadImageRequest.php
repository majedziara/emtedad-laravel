<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadImageRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['image' => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(config('emtedad.content.image_max_kb')), Rule::dimensions()->maxWidth(6000)->maxHeight(6000)]];
    }
}
