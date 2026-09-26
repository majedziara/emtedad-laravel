<?php

namespace App\Models;

use App\Enum\CurrencyEnum;
use App\Enum\DonationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Donation extends Model
{
    protected $fillable = ['humanitarian_case_id', 'user_id', 'donor_name', 'donor_email', 'donor_phone', 'amount_minor', 'currency', 'status', 'is_anonymous', 'message', 'paid_at'];

    protected $hidden = ['donor_name', 'donor_email', 'donor_phone'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'currency' => CurrencyEnum::class, 'status' => DonationStatusEnum::class, 'is_anonymous' => 'boolean', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }

    public function humanitarianCase(): BelongsTo
    {
        return $this->belongsTo(HumanitarianCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
