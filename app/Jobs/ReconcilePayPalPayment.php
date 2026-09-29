<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePayPalPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue(config('paypal.queue'));
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PaymentService $service): void
    {
        $payment = Payment::find($this->paymentId);
        if ($payment && $payment->provider_order_id) {
            $service->reconcile($payment, captureApproved: true);
        }
    }
}
