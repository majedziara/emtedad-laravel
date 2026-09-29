<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ContentTranslationService
{
    public function save(Model $parent, array $translations): void
    {
        foreach ($translations as $translation) {
            $parent->translations()->updateOrCreate(['locale' => $translation['locale']], Arr::except($translation, ['locale']));
        }
    }

    public function delete(Model $parent, string $locale): void
    {
        if ($locale === config('emtedad.default_locale')) {
            throw ValidationException::withMessages(['locale' => __('content.default_locale_required')]);
        }
        $parent->translations()->where('locale', $locale)->firstOrFail()->delete();
    }
}
