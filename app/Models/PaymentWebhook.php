<?php

namespace App\Models;

use App\Enum\PaymentProviderEnum;
use App\Enum\WebhookStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhook extends Model
{
    protected $fillable = ['payment_id', 'provider', 'event_id', 'event_type', 'payload', 'status', 'attempts', 'processed_at', 'error_message'];

    protected $hidden = ['payload', 'error_message'];

    protected function casts(): array
    {
        return ['provider' => PaymentProviderEnum::class, 'payload' => 'array', 'status' => WebhookStatusEnum::class, 'attempts' => 'integer', 'processed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
