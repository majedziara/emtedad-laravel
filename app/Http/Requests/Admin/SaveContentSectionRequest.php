<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ContentRequest;
use App\Rules\PlainText;
use App\Rules\WebsiteUrl;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveContentSectionRequest extends ContentRequest
{
    public function rules(): array
    {
        $id = $this->route('contentSection')?->id;
        $rules = [
            'key' => $id ? ['prohibited'] : ['required', 'string', 'max:120', 'regex:/\A[a-z][a-z0-9_]*\z/', Rule::unique('content_sections', 'key')],
            'page' => [$id ? 'sometimes' : 'required', Rule::in(['home', 'about', 'contact', 'footer', 'privacy', 'terms'])],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'image_path' => ['prohibited'],
        ] + $this->translationRules('content_section_translations', 'content_section_id', $id, [
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:30000'],
            'button_label' => ['nullable', 'string', 'max:100'],
            'button_url' => ['nullable', 'string', 'max:255', new WebsiteUrl(true)],
        ]);
        $rules['translations.*'] = ['required', 'array:locale,title,subtitle,body,button_label,button_url,items'];

        return $rules + [
            'translations.*.items' => ['nullable', 'array', 'list', 'max:20'],
            'translations.*.items.*' => ['required', 'array:title,body'],
            'translations.*.items.*.title' => ['required', 'string', 'max:200', new PlainText],
            'translations.*.items.*.body' => ['nullable', 'string', 'max:2000', new PlainText],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->requireDefaultLocale($v, ! $this->route('contentSection'))];
    }
}
