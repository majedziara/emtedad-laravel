<?php

namespace App\Models;

use Database\Factories\SettingTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingTranslation extends Model
{
    /** @use HasFactory<SettingTranslationFactory> */
    use HasFactory;

    protected $fillable = ['setting_id', 'locale', 'organization_name', 'address', 'seo_title', 'seo_description'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
