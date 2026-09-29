<?php

namespace App\Models;

use App\Enum\CurrencyEnum;
use App\Enum\PaymentProviderEnum;
use App\Enum\PaymentStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = ['donation_id', 'provider', 'provider_order_id', 'provider_transaction_id', 'idempotency_key', 'amount_minor', 'refunded_amount_minor', 'currency', 'status', 'failure_code', 'metadata', 'paid_at', 'environment', 'request_fingerprint', 'capture_request_id', 'approval_url', 'create_started_at', 'last_synced_at', 'needs_review'];

    protected $hidden = ['metadata', 'request_fingerprint', 'capture_request_id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProviderEnum::class,
            'amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'currency' => CurrencyEnum::class,
            'status' => PaymentStatusEnum::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'create_started_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'needs_review' => 'boolean',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(PaymentWebhook::class);
    }
}
