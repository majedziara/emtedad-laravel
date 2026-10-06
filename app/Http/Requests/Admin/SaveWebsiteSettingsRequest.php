<?php

namespace App\Http\Requests\Admin;

use App\Enum\CurrencyEnum;
use App\Http\Requests\ContentRequest;
use App\Rules\PlainText;
use App\Rules\WebsiteUrl;
use App\Services\WebsiteSettingsService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWebsiteSettingsRequest extends ContentRequest
{
    public function rules(): array
    {
        $fields = array_keys(config('website.defaults'));
        $rules = [
            'settings' => ['sometimes', 'array:'.implode(',', $fields)],
            'settings.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'settings.phone' => ['sometimes', 'nullable', 'string', 'max:32', new PlainText],
            'settings.donations_enabled' => ['sometimes', 'boolean'],
            'settings.display_currencies' => ['sometimes', 'array', 'list', 'min:1', 'max:3'],
            'settings.display_currencies.*' => ['required', 'distinct', Rule::enum(CurrencyEnum::class)],
        ];
        foreach (['facebook_url', 'instagram_url', 'youtube_url', 'linkedin_url', 'x_url'] as $field) {
            $rules['settings.'.$field] = ['sometimes', 'nullable', 'string', 'max:2048', new WebsiteUrl];
        }
        $translations = $this->translationRules('setting_translations', 'setting_id', app(WebsiteSettingsService::class)->get()->id, [
            'organization_name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
        ]);
        $translations['translations'][0] = 'sometimes';

        return $rules + $translations;
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            if ($this->has('translations')) {
                $this->requireDefaultLocale($v, ! app(WebsiteSettingsService::class)->get()->translations->contains('locale', config('emtedad.default_locale')));
            }
        }];
    }
}
