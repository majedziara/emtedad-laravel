<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'group', 'value', 'is_public'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_public' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SettingTranslation::class);
    }
}
