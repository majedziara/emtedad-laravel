<?php

namespace App\Models;

use Database\Factories\PartnerTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerTranslation extends Model
{
    /** @use HasFactory<PartnerTranslationFactory> */
    use HasFactory;

    protected $fillable = ['partner_id', 'locale', 'name', 'description'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
