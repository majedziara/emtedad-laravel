<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ContentRequest;
use App\Rules\WebsiteUrl;
use Illuminate\Validation\Validator;

class SavePartnerRequest extends ContentRequest
{
    public function rules(): array
    {
        return [
            'website_url' => ['sometimes', 'nullable', 'string', 'max:2048', new WebsiteUrl],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'logo_path' => ['prohibited'],
        ] + $this->translationRules('partner_translations', 'partner_id', $this->route('partner')?->id, [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->requireDefaultLocale($v, ! $this->route('partner'))];
    }
}
