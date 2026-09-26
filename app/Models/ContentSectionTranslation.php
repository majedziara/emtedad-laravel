<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSectionTranslation extends Model
{
    protected $fillable = ['content_section_id', 'locale', 'title', 'subtitle', 'body', 'button_label', 'button_url', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function contentSection(): BelongsTo
    {
        return $this->belongsTo(ContentSection::class);
    }
}
