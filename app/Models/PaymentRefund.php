<?php

namespace App\Models;

use App\Enum\CurrencyEnum;
use App\Enum\PaymentRefundStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = ['payment_id', 'provider_refund_id', 'amount_minor', 'currency', 'status'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => CurrencyEnum::class,
            'status' => PaymentRefundStatusEnum::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
