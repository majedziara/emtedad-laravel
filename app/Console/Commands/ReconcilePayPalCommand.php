<?php

namespace App\Console\Commands;

use App\Enum\WebhookStatusEnum;
use App\Jobs\ProcessPayPalWebhook;
use App\Jobs\ReconcilePayPalPayment;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use Illuminate\Console\Command;

class ReconcilePayPalCommand extends Command
{
    protected $signature = 'paypal:reconcile {--payment= : Local payment ID for a manual reconciliation}';

    protected $description = 'Queue PayPal reconciliation and retry unprocessed verified webhooks';

    public function handle(): int
    {
        if ($this->option('payment')) {
            $payment = Payment::where('provider', 'paypal')->where('environment', config('paypal.mode'))->findOrFail($this->option('payment'));
            ReconcilePayPalPayment::dispatch($payment->id);
            $this->info('Payment reconciliation queued.');

            return self::SUCCESS;
        }
        PaymentWebhook::where('provider', 'paypal')->whereIn('status', [WebhookStatusEnum::RECEIVED->value, WebhookStatusEnum::FAILED->value])->where('created_at', '>=', now()->subDays(7))->where('updated_at', '<=', now()->subMinutes(10))->where('attempts', '<', 30)->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                ProcessPayPalWebhook::dispatch($row->id);
            }
        });
        Payment::where('provider', 'paypal')->where('environment', config('paypal.mode'))->whereNotNull('provider_order_id')->where('created_at', '>=', now()->subDays(7))->where(fn($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinutes(15)))->where(fn($q) => $q->where('status', 'pending')->orWhere('needs_review', true))->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                ReconcilePayPalPayment::dispatch($row->id);
            }
        });
        $this->info('PayPal recovery jobs queued.');

        return self::SUCCESS;
    }
}
