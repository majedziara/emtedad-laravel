<?php

namespace App\Services;

use App\Enum\DonationStatusEnum;
use App\Enum\PaymentRefundStatusEnum;
use App\Enum\PaymentStatusEnum;
use App\Exceptions\PayPalException;
use App\Helpers\Money;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(private readonly PayPalService $paypal, private readonly DonationService $donations) {}

    public function createOrder(Payment $payment): Payment
    {
        return $this->locked($payment, function (Payment $payment) {
            if ($payment->provider_order_id) {
                return $payment;
            }
            $payload = $payment->metadata['create_payload'] ?? null;
            if (! is_array($payload)) {
                throw new PayPalException('LEGACY_PAYMENT_REQUIRES_REVIEW', 409);
            }
            if ($payment->create_started_at && $payment->create_started_at->lt(now()->subMinutes(config('paypal.create_retry_minutes')))) {
                $this->review($payment, 'CREATE_OUTCOME_UNKNOWN');
            }
            $payment->forceFill(['create_started_at' => $payment->create_started_at ?? now()])->save();
            // The exact payload and request ID survive timeouts and process restarts.
            $order = $this->paypal->createOrder($payload, $payment->idempotency_key);
            $this->validateOrder($payment, $order);
            $approval = collect($order['links'] ?? [])->first(fn($link) => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));
            $url = $approval['href'] ?? null;
            $host = $payment->environment === 'sandbox' ? 'www.sandbox.paypal.com' : 'www.paypal.com';
            if ($url !== null && (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host)) {
                $this->review($payment, 'INVALID_APPROVAL_URL');
            }
            if ($url === null && ! in_array($order['status'] ?? null, ['APPROVED', 'COMPLETED'], true)) {
                $this->review($payment, 'APPROVAL_URL_MISSING');
            }
            $payment->forceFill([
                'provider_order_id' => $order['id'],
                'approval_url' => $url,
                'failure_code' => null,
                'needs_review' => false,
                'last_synced_at' => now(),
            ])->save();

            return $payment;
        });
    }

    public function reconcile(Payment $payment, bool $captureApproved = false, ?string $knownOrderId = null, ?string $refundId = null, bool $reversed = false): Payment
    {
        return $this->locked($payment, function (Payment $payment) use ($captureApproved, $knownOrderId, $refundId, $reversed) {
            $orderId = $payment->provider_order_id ?? $knownOrderId;
            if (! $orderId) {
                throw new PayPalException('ORDER_NOT_CREATED', 409);
            }
            $order = $this->paypal->order($orderId);
            $this->validateOrder($payment, $order);
            if (! $payment->provider_order_id) {
                $payment->forceFill(['provider_order_id' => $orderId])->save();
            }
            $captures = data_get($order, 'purchase_units.0.payments.captures', []);
            if ($captures === [] && $captureApproved && ($order['status'] ?? null) === 'APPROVED') {
                if ($payment->provider_transaction_id || $payment->paid_at) {
                    $this->review($payment, 'CAPTURE_DETAILS_MISSING');
                }
                $this->donations->ensurePayable($payment->donation->humanitarianCase);
                try {
                    $order = $this->paypal->captureOrder($orderId, $payment->capture_request_id);
                } catch (PayPalException $e) {
                    if ($e->reason !== 'ORDER_ALREADY_CAPTURED') {
                        throw $e;
                    }
                    $order = $this->paypal->order($orderId);
                }
                $this->validateOrder($payment, $order);
                $captures = data_get($order, 'purchase_units.0.payments.captures', []);
            }
            if (count($captures) > 1) {
                $this->review($payment, 'MULTIPLE_CAPTURES_REQUIRE_REVIEW');
            }
            $captureId = $captures[0]['id'] ?? $payment->provider_transaction_id;
            if (! $captureId) {
                if ($refundId || $reversed || ($order['status'] ?? null) === 'COMPLETED') {
                    $this->review($payment, 'CAPTURE_DETAILS_MISSING');
                }
                $payment->forceFill(['last_synced_at' => now()])->save();

                return $payment;
            }
            // The capture endpoint is authoritative for pending/refunded state.
            $capture = $this->paypal->capture($captureId);
            $this->validateCapture($payment, $capture, $captureId);
            $refundIds = collect(data_get($order, 'purchase_units.0.payments.refunds', []))->pluck('id');
            if ($refundId) {
                $refundIds->push($refundId);
            }
            $refunds = [];
            foreach ($refundIds->filter()->unique()->values() as $id) {
                $refund = $this->paypal->refund($id);
                $this->validateRefund($payment, $refund, $captureId, $id);
                $refunds[] = $refund;
            }

            return $this->applyCapture($payment, $capture, $refunds, $reversed);
        });
    }

    public function paymentForOrder(array $order): ?Payment
    {
        $orderId = $order['id'] ?? null;
        $payment = Payment::where('provider', 'paypal')->where('environment', config('paypal.mode'))->where('provider_order_id', $orderId)->first();
        if ($payment) {
            return $payment;
        }
        $publicId = data_get($order, 'purchase_units.0.custom_id');
        if (! is_string($publicId)) {
            return null;
        }

        return Payment::where('provider', 'paypal')->where('environment', config('paypal.mode'))->whereNull('provider_order_id')->whereNotNull('request_fingerprint')->whereHas('donation', fn($query) => $query->where('public_id', $publicId))->first();
    }

    public function linkedCaptureId(array $refund): ?string
    {
        // Only extract an ID; never make requests to a webhook-provided URL.
        foreach ($refund['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) !== 'up') {
                continue;
            }
            $url = $link['href'] ?? '';
            $hosts = config('paypal.mode') === 'sandbox' ? ['api-m.sandbox.paypal.com', 'api.sandbox.paypal.com'] : ['api-m.paypal.com', 'api.paypal.com'];
            if (parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), $hosts, true) && preg_match('#\A/v2/payments/captures/([A-Za-z0-9]+)\z#', (string) parse_url($url, PHP_URL_PATH), $match)) {
                return $match[1];
            }
        }

        return null;
    }

    private function validateOrder(Payment $payment, array $order): void
    {
        $units = $order['purchase_units'] ?? [];
        $unit = $units[0] ?? [];
        $reference = $payment->donation->public_id;
        $expectedMerchant = data_get($payment->metadata, 'create_payload.purchase_units.0.payee.merchant_id');
        $valid = is_string($order['id'] ?? null) && $order['id'] !== '' && (! $payment->provider_order_id || $payment->provider_order_id === $order['id']) && ($order['intent'] ?? null) === 'CAPTURE' && count($units) === 1 && ($unit['reference_id'] ?? null) === $reference && ($unit['custom_id'] ?? null) === $reference && ($unit['invoice_id'] ?? null) === 'EMT-' . $reference && is_string($expectedMerchant) && $expectedMerchant !== '' && data_get($unit, 'payee.merchant_id') === $expectedMerchant && $this->matchesAmount($unit['amount'] ?? [], $payment->amount_minor, $payment->currency->value);
        if (! $valid) {
            $this->review($payment, 'ORDER_DATA_MISMATCH');
        }
    }

    private function validateCapture(Payment $payment, array $capture, string $captureId): void
    {
        $relatedOrder = data_get($capture, 'supplementary_data.related_ids.order_id');
        $merchant = data_get($capture, 'payee.merchant_id');
        $customId = $capture['custom_id'] ?? null;
        $invoiceId = $capture['invoice_id'] ?? null;
        $valid = ($capture['id'] ?? null) === $captureId && (! $payment->provider_transaction_id || $payment->provider_transaction_id === $captureId) && (! $relatedOrder || $relatedOrder === $payment->provider_order_id) && (! $merchant || $merchant === data_get($payment->metadata, 'create_payload.purchase_units.0.payee.merchant_id')) && (! $customId || $customId === $payment->donation->public_id) && (! $invoiceId || $invoiceId === 'EMT-' . $payment->donation->public_id) && $this->matchesAmount($capture['amount'] ?? [], $payment->amount_minor, $payment->currency->value);
        if (! $valid) {
            $this->review($payment, 'CAPTURE_DATA_MISMATCH');
        }
        $other = Payment::where('provider', 'paypal')->where('provider_transaction_id', $captureId)->whereKeyNot($payment->id)->exists();
        if ($other) {
            $this->review($payment, 'CAPTURE_ALREADY_LINKED');
        }
    }

    private function validateRefund(Payment $payment, array $refund, string $captureId, string $refundId): void
    {
        try {
            $amount = Money::minor(data_get($refund, 'amount.value'));
        } catch (InvalidArgumentException) {
            $amount = -1;
        }
        $valid = ($refund['id'] ?? null) === $refundId && $this->linkedCaptureId($refund) === $captureId && data_get($refund, 'amount.currency_code') === $payment->currency->value && $amount > 0 && $amount <= $payment->amount_minor && PaymentRefundStatusEnum::tryFrom((string) ($refund['status'] ?? '')) !== null;
        if (! $valid || PaymentRefund::where('provider_refund_id', $refundId)->where('payment_id', '!=', $payment->id)->exists()) {
            $this->review($payment, 'REFUND_DATA_MISMATCH');
        }
    }

    private function applyCapture(Payment $payment, array $capture, array $refunds, bool $reversed): Payment
    {
        return DB::transaction(function () use ($payment, $capture, $refunds, $reversed) {
            $locked = Payment::with('donation')->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            foreach ($refunds as $refund) {
                $existing = $locked->refunds()->where('provider_refund_id', $refund['id'])->first();
                // A delayed pending notification cannot undo a completed refund.
                if ($existing?->status === PaymentRefundStatusEnum::COMPLETED) {
                    continue;
                }
                $locked->refunds()->updateOrCreate(['provider_refund_id' => $refund['id']], [
                    'amount_minor' => Money::minor($refund['amount']['value']),
                    'currency' => $refund['amount']['currency_code'],
                    'status' => $refund['status'],
                ]);
            }
            $refunded = (int) $locked->refunds()->where('status', 'COMPLETED')->sum('amount_minor');
            // PayPal refund responses can include the cumulative refunded total.
            foreach ($refunds as $refund) {
                $total = data_get($refund, 'seller_payable_breakdown.total_refunded_amount');
                if (($refund['status'] ?? null) === 'COMPLETED' && is_array($total) && ($total['currency_code'] ?? null) === $locked->currency->value) {
                    $refunded = max($refunded, Money::minor($total['value'] ?? null));
                }
            }
            if ($refunded > $locked->amount_minor) {
                throw new PayPalException('REFUND_TOTAL_MISMATCH', 409);
            }
            $remoteStatus = $capture['status'] ?? '';
            $state = match ($remoteStatus) {
                'COMPLETED' => PaymentStatusEnum::COMPLETED,
                'PENDING' => PaymentStatusEnum::PENDING,
                'DECLINED', 'DENIED', 'FAILED' => PaymentStatusEnum::FAILED,
                'PARTIALLY_REFUNDED' => PaymentStatusEnum::PARTIALLY_REFUNDED,
                'REFUNDED' => PaymentStatusEnum::REFUNDED,
                default => null,
            };
            if (! $state) {
                throw new PayPalException('UNKNOWN_CAPTURE_STATUS', 409);
            }
            $refunded = max($refunded, $locked->refunded_amount_minor);
            if ($state === PaymentStatusEnum::REFUNDED || $locked->status === PaymentStatusEnum::REFUNDED) {
                $refunded = $locked->amount_minor;
            }
            if ($refunded > 0) {
                $state = $refunded === $locked->amount_minor ? PaymentStatusEnum::REFUNDED : PaymentStatusEnum::PARTIALLY_REFUNDED;
            } elseif ($locked->paid_at && in_array($state, [PaymentStatusEnum::PENDING, PaymentStatusEnum::FAILED], true)) {
                $state = $locked->status;
            }
            if ($reversed || $locked->status === PaymentStatusEnum::REVERSED) {
                $state = PaymentStatusEnum::REVERSED;
            }
            $paidStates = [PaymentStatusEnum::COMPLETED, PaymentStatusEnum::PARTIALLY_REFUNDED, PaymentStatusEnum::REFUNDED, PaymentStatusEnum::REVERSED];
            $paidAt = in_array($state, $paidStates, true) ? $locked->paid_at ?? now() : null;
            $missingRefund = $remoteStatus === 'PARTIALLY_REFUNDED' && $refunded === 0;
            $locked->forceFill([
                'provider_transaction_id' => $capture['id'],
                'status' => $state,
                'refunded_amount_minor' => $refunded,
                'paid_at' => $paidAt,
                'last_synced_at' => now(),
                'needs_review' => $missingRefund,
                'failure_code' => $missingRefund ? 'REFUND_DETAILS_PENDING' : ($state === PaymentStatusEnum::FAILED ? 'CAPTURE_DECLINED' : null),
            ])->save();
            $locked->donation->forceFill(['status' => DonationStatusEnum::from($state->value), 'paid_at' => $paidAt])->save();

            return $locked;
        });
    }

    private function matchesAmount(array $amount, int $expected, string $currency): bool
    {
        try {
            return ($amount['currency_code'] ?? null) === $currency && Money::minor($amount['value'] ?? null) === $expected;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function review(Payment $payment, string $reason): never
    {
        $payment->forceFill(['needs_review' => true, 'failure_code' => $reason])->save();
        throw new PayPalException($reason, 409);
    }

    private function locked(Payment $payment, callable $callback): Payment
    {
        $lock = Cache::lock('payment:paypal:' . $payment->id, config('paypal.lock_seconds'));
        if (! $lock->get()) {
            throw new PayPalException('PAYMENT_BUSY', 409);
        }
        try {
            $payment->refresh()->load('donation.humanitarianCase');
            if ($payment->environment !== config('paypal.mode')) {
                throw new PayPalException('PAYMENT_ENVIRONMENT_MISMATCH', 409);
            }

            return $callback($payment);
        } catch (PayPalException $e) {
            if (in_array($e->reason, ['UNKNOWN_CAPTURE_STATUS', 'REFUND_TOTAL_MISMATCH'], true)) {
                $payment->forceFill(['needs_review' => true, 'failure_code' => $e->reason])->save();
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
