<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ContentRequest;
use Illuminate\Validation\Validator;

class SaveCategoryRequest extends ContentRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'image_path' => ['prohibited'],
        ] + $this->translationRules('category_translations', 'category_id', $this->route('category')?->id, [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    public function after(): array
    {
        return [fn(Validator $v) => $this->requireDefaultLocale($v, ! $this->route('category'))];
    }
}
