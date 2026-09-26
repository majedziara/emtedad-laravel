<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseTranslation extends Model
{
    protected $fillable = ['humanitarian_case_id', 'locale', 'title', 'slug', 'summary', 'story', 'location', 'meta_title', 'meta_description'];

    public function humanitarianCase(): BelongsTo
    {
        return $this->belongsTo(HumanitarianCase::class);
    }
}
