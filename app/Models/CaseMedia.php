<?php

namespace App\Models;

use App\Enum\MediaTypeEnum;
use App\Enum\MediaVisibilityEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseMedia extends Model
{
    protected $table = 'case_media';

    protected $fillable = ['humanitarian_case_id', 'uploaded_by', 'type', 'visibility', 'disk', 'path', 'original_name', 'mime_type', 'size', 'sort_order'];

    protected $hidden = ['path', 'original_name'];

    protected function casts(): array
    {
        return ['type' => MediaTypeEnum::class, 'visibility' => MediaVisibilityEnum::class, 'size' => 'integer', 'sort_order' => 'integer'];
    }

    public function humanitarianCase(): BelongsTo
    {
        return $this->belongsTo(HumanitarianCase::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
