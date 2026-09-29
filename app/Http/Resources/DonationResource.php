<?php

namespace App\Http\Resources;

use App\Enum\PaymentStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('admin.donations.*');

        return [
            'public_id' => $this->public_id,
            'case_public_id' => $this->humanitarianCase?->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency->value,
            'status' => $this->status->value,
            'is_anonymous' => $this->is_anonymous,
            'donor_name' => $this->donor_name,
            'donor_email' => $this->donor_email,
            'donor_phone' => $this->donor_phone,
            'message' => $this->message,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'payments' => $this->payments->map(fn($payment) => ($admin ? ['id' => $payment->id, 'failure_code' => $payment->failure_code] : []) + [
                'provider' => $payment->provider->value,
                'environment' => $payment->environment,
                'provider_order_id' => $payment->provider_order_id,
                'provider_capture_id' => $payment->provider_transaction_id,
                'status' => $payment->status->value,
                'approval_url' => $payment->status === PaymentStatusEnum::PENDING && ! $payment->provider_transaction_id ? $payment->approval_url : null,
                'amount_minor' => $payment->amount_minor,
                'refunded_amount_minor' => $payment->refunded_amount_minor,
                // This is before PayPal fees. Review-required amounts are not reported as confirmed totals.
                'recognized_amount_minor' => $payment->needs_review ? null : match ($payment->status) {
                    PaymentStatusEnum::COMPLETED, PaymentStatusEnum::PARTIALLY_REFUNDED => $payment->amount_minor - $payment->refunded_amount_minor,
                    default => 0,
                },
                'needs_review' => $payment->needs_review,
                'last_synced_at' => $payment->last_synced_at?->toISOString(),
            ]),
        ];
    }
}
