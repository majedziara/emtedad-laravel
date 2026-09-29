<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

trait SelectsContentTranslation
{
    protected function contentTranslation(): ?Model
    {
        return $this->translations->firstWhere('locale', app()->getLocale()) ?? $this->translations->firstWhere('locale', config('emtedad.default_locale'));
    }
}
