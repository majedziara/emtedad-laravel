<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseUpdateTranslation extends Model
{
    protected $fillable = ['case_update_id', 'locale', 'title', 'body'];

    public function caseUpdate(): BelongsTo
    {
        return $this->belongsTo(CaseUpdate::class);
    }
}
