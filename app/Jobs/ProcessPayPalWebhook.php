<?php

namespace App\Jobs;

use App\Services\PayPalWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayPalWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 240;

    public function __construct(public readonly int $webhookId)
    {
        $this->onQueue(config('paypal.queue'));
    }

    public function backoff(): array
    {
        return [15, 60, 180, 600, 1800];
    }

    public function handle(PayPalWebhookService $service): void
    {
        $service->process($this->webhookId);
    }
}
