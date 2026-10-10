<?php

namespace App\Http\Requests\Admin;

use App\Enum\CurrencyEnum;
use App\Http\Requests\ContentRequest;
use App\Rules\GoFundMeLink;
use App\Rules\PalestinianIban;
use App\Rules\PlainText;
use App\Rules\WebsiteUrl;
use App\Services\WebsiteSettingsService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWebsiteSettingsRequest extends ContentRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $settings = $this->input('settings');
        if (! is_array($settings)) {
            return;
        }

        foreach (config('website.bank_currencies') as $currency) {
            $key = 'bank_iban_'.strtolower($currency);
            if (isset($settings[$key]) && is_string($settings[$key])) {
                $settings[$key] = strtoupper(preg_replace('/\s+/u', '', $settings[$key]));
                $settings[$key] = $settings[$key] ?: null;
            }
        }
        if (isset($settings['bank_swift']) && is_string($settings['bank_swift'])) {
            $settings['bank_swift'] = strtoupper(trim($settings['bank_swift'])) ?: null;
        }

        $this->merge(['settings' => $settings]);
    }

    public function rules(): array
    {
        $fields = array_keys(config('website.defaults'));
        $rules = [
            'settings' => ['sometimes', 'array:'.implode(',', $fields)],
            'settings.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'settings.phone' => ['sometimes', 'nullable', 'string', 'max:32', new PlainText],
            'settings.donations_enabled' => ['sometimes', 'boolean'],
            'settings.gofundme_enabled' => ['sometimes', 'boolean'],
            'settings.gofundme_url' => ['sometimes', 'nullable', 'string', 'max:2048', new GoFundMeLink],
            'settings.bank_transfer_enabled' => ['sometimes', 'boolean'],
            'settings.bank_name' => ['sometimes', 'nullable', 'string', 'max:160', new PlainText],
            'settings.bank_beneficiary_name' => ['sometimes', 'nullable', 'string', 'max:160', new PlainText],
            'settings.bank_beneficiary_name_en' => ['sometimes', 'nullable', 'string', 'max:160', new PlainText],
            'settings.bank_swift' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z/'],
            'settings.display_currencies' => ['sometimes', 'array', 'list', 'min:1', 'max:3'],
            'settings.display_currencies.*' => ['required', 'distinct', Rule::enum(CurrencyEnum::class)],
        ];
        foreach (['facebook_url', 'instagram_url', 'youtube_url', 'linkedin_url', 'x_url'] as $field) {
            $rules['settings.'.$field] = ['sometimes', 'nullable', 'string', 'max:2048', new WebsiteUrl];
        }
        foreach (config('website.bank_currencies') as $currency) {
            $rules['settings.bank_iban_'.strtolower($currency)] = ['sometimes', 'nullable', 'string', 'max:29', new PalestinianIban];
        }
        foreach (['key', 'group', 'is_public', 'value', 'logo_path', 'favicon_path'] as $field) {
            $rules[$field] = ['prohibited'];
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
