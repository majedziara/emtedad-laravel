<?php

namespace App\Models;

use App\Enum\CasePriorityEnum;
use App\Enum\CaseStatusEnum;
use App\Enum\CurrencyEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HumanitarianCase extends Model
{
    use SoftDeletes;

    protected $fillable = ['category_id', 'created_by', 'updated_by', 'beneficiary_reference', 'beneficiaries_count', 'country_code', 'target_amount_minor', 'currency', 'status', 'priority', 'is_featured', 'cover_image_path', 'starts_at', 'ends_at', 'published_at', 'completed_at'];

    protected $hidden = ['beneficiary_reference'];

    protected function casts(): array
    {
        return ['beneficiaries_count' => 'integer', 'target_amount_minor' => 'integer', 'currency' => CurrencyEnum::class, 'status' => CaseStatusEnum::class, 'priority' => CasePriorityEnum::class, 'is_featured' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CaseTranslation::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(CaseMedia::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CaseUpdate::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }
}
