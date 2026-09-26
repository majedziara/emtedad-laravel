<?php

namespace App\Models;

use App\Enum\PublicationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseUpdate extends Model
{
    protected $fillable = ['humanitarian_case_id', 'created_by', 'image_path', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['status' => PublicationStatusEnum::class, 'published_at' => 'datetime'];
    }

    public function humanitarianCase(): BelongsTo
    {
        return $this->belongsTo(HumanitarianCase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CaseUpdateTranslation::class);
    }
}
