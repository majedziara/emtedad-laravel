<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class ContentRequest extends ApiRequest
{
    protected function translationRules(string $table, string $foreignKey, ?int $parentId, array $fields): array
    {
        $rules = [
            'translations' => [$parentId ? 'sometimes' : 'required', 'array', 'list', 'min:1', 'max:' . config('emtedad.content.max_translations')],
            'translations.*' => ['required', 'array:locale,' . implode(',', array_keys($fields))],
            'translations.*.locale' => ['required', 'string', 'distinct', Rule::in(config('emtedad.locales'))],
        ];
        foreach ($fields as $field => $fieldRules) {
            $rules['translations.*.' . $field] = array_merge($fieldRules, [$field === 'slug' ? 'regex:/\A[\p{L}\p{M}\p{N}]+(?:-[\p{L}\p{M}\p{N}]+)*\z/u' : new PlainText]);
        }
        $translations = $this->input('translations', []);
        if (is_array($translations) && count($translations) <= config('emtedad.content.max_translations')) {
            foreach ($translations as $index => $translation) {
                if (! is_int($index) || ! is_array($translation) || ! is_string($translation['locale'] ?? null) || ! array_key_exists('slug', $fields)) {
                    continue;
                }
                $unique = Rule::unique($table, 'slug')->where('locale', $translation['locale']);
                if ($parentId) {
                    $unique->where(fn($query) => $query->where($foreignKey, '!=', $parentId));
                }
                $rules['translations.' . $index . '.slug'] = array_merge($rules['translations.*.slug'], [$unique]);
            }
        }

        return $rules;
    }

    protected function requireDefaultLocale(Validator $validator, bool $creating): void
    {
        if (! $creating || $validator->errors()->isNotEmpty()) {
            return;
        }
        if (! in_array(config('emtedad.default_locale'), array_column($this->input('translations', []), 'locale'), true)) {
            $validator->errors()->add('translations', __('content.default_locale_required'));
        }
    }
}
