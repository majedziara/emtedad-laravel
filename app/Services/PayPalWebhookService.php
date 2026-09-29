<?php

namespace App\Services;

use App\Enum\PayPalEventEnum;
use App\Enum\WebhookStatusEnum;
use App\Exceptions\PayPalException;
use App\Jobs\ProcessPayPalWebhook;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Throwable;

class PayPalWebhookService
{
    public function __construct(private readonly PayPalService $paypal, private readonly PaymentService $payments) {}

    public function receive(Request $request): PaymentWebhook
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 262144, 413);
        try {
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, __('payments.invalid_webhook'));
        }
        abort_unless(is_array($event), 400, __('payments.invalid_webhook'));
        $validator = Validator::make($event, [
            'id' => ['required', 'string', 'max:160'],
            'event_type' => ['required', 'string', 'max:160'],
            'resource' => ['required', 'array'],
            'resource.id' => ['required', 'string', 'max:160'],
        ]);
        abort_if($validator->fails(), 400, __('payments.invalid_webhook'));
        $headers = [];
        foreach (
            [
                'auth_algo' => 'PAYPAL-AUTH-ALGO',
                'cert_url' => 'PAYPAL-CERT-URL',
                'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
                'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
                'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
            ] as $key => $header
        ) {
            $value = $request->header($header);
            abort_unless(is_string($value) && $value !== '' && strlen($value) <= 4096, 400, __('payments.invalid_webhook'));
            $headers[$key] = $value;
        }
        abort_unless($this->paypal->verifyWebhook($raw, $headers), 403, __('payments.invalid_signature'));
        // The raw body was verified. Retain only IDs needed to fetch authoritative data.
        $webhook = PaymentWebhook::firstOrCreate(['provider' => 'paypal', 'event_id' => $event['id']], [
            'event_type' => $event['event_type'],
            'payload' => ['resource_id' => $event['resource']['id'], 'environment' => config('paypal.mode')],
            'status' => PayPalEventEnum::tryFrom($event['event_type']) ? WebhookStatusEnum::RECEIVED : WebhookStatusEnum::IGNORED,
            'processed_at' => PayPalEventEnum::tryFrom($event['event_type']) ? null : now(),
        ]);
        if (! in_array($webhook->status, [WebhookStatusEnum::PROCESSED, WebhookStatusEnum::IGNORED], true)) {
            ProcessPayPalWebhook::dispatch($webhook->id);
        }

        return $webhook;
    }

    public function process(int $id): void
    {
        $lock = Cache::lock('paypal:webhook:' . $id, config('paypal.lock_seconds'));
        if (! $lock->get()) {
            throw new PayPalException('WEBHOOK_BUSY', 409);
        }
        $webhook = null;
        try {
            $webhook = PaymentWebhook::findOrFail($id);
            if (in_array($webhook->status, [WebhookStatusEnum::PROCESSED, WebhookStatusEnum::IGNORED], true)) {
                return;
            }
            $webhook->increment('attempts');
            $event = PayPalEventEnum::tryFrom($webhook->event_type);
            if (! $event) {
                $this->finish($webhook, WebhookStatusEnum::IGNORED);

                return;
            }
            if (($webhook->payload['environment'] ?? null) !== config('paypal.mode')) {
                throw new PayPalException('WEBHOOK_ENVIRONMENT_MISMATCH', 409);
            }
            $resourceId = $webhook->payload['resource_id'];
            $refundId = null;
            $captureId = null;
            if ($event === PayPalEventEnum::ORDER_APPROVED) {
                $order = $this->paypal->order($resourceId);
            } else {
                if ($event === PayPalEventEnum::CAPTURE_REFUNDED) {
                    $refundId = $resourceId;
                    $refund = $this->paypal->refund($refundId);
                    $captureId = $this->payments->linkedCaptureId($refund);
                    if (! $captureId) {
                        throw new PayPalException('REFUND_CAPTURE_LINK_MISSING', 409);
                    }
                } else {
                    $captureId = $resourceId;
                }
                $capture = $this->paypal->capture($captureId);
                $orderId = data_get($capture, 'supplementary_data.related_ids.order_id');
                if (! $orderId) {
                    $orderId = Payment::where('provider', 'paypal')->where('environment', config('paypal.mode'))->where('provider_transaction_id', $captureId)->value('provider_order_id');
                }
                if (! $orderId) {
                    throw new PayPalException('CAPTURE_ORDER_LINK_MISSING', 409);
                }
                $order = $this->paypal->order($orderId);
            }
            $payment = $this->payments->paymentForOrder($order);
            if (! $payment) {
                $this->finish($webhook, WebhookStatusEnum::IGNORED);

                return;
            }
            $webhook->forceFill(['payment_id' => $payment->id])->save();
            if ($captureId) {
                $knownCaptures = collect(data_get($order, 'purchase_units.0.payments.captures', []))->pluck('id');
                if (! $knownCaptures->contains($captureId) && $payment->provider_transaction_id !== $captureId) {
                    throw new PayPalException('WEBHOOK_CAPTURE_ORDER_MISMATCH', 409);
                }
            }
            $this->payments->reconcile($payment, captureApproved: $event === PayPalEventEnum::ORDER_APPROVED, knownOrderId: $order['id'], refundId: $refundId, reversed: $event === PayPalEventEnum::CAPTURE_REVERSED);
            $this->finish($webhook, WebhookStatusEnum::PROCESSED);
        } catch (Throwable $e) {
            if ($webhook) {
                $webhook->forceFill(['status' => WebhookStatusEnum::FAILED, 'error_message' => $e instanceof PayPalException ? $e->reason : 'WEBHOOK_PROCESSING_FAILED'])->save();
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function finish(PaymentWebhook $webhook, WebhookStatusEnum $status): void
    {
        $webhook->forceFill([
            'status' => $status,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }
}
