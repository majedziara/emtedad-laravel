<?php

namespace Tests\Feature\Emtedad;

use App\Exceptions\PayPalException;
use App\Helpers\Money;
use App\Jobs\ProcessPayPalWebhook;
use App\Jobs\ReconcilePayPalPayment;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Services\PaymentService;
use App\Services\PayPalWebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class PayPalTest extends EmtedadTestCase
{
    private HumanitarianCase $case;

    private array $orders = [];

    private array $refunds = [];

    private string $captureState = 'COMPLETED';

    private ?string $wrongAmount = null;

    private ?string $wrongCurrency = null;

    private bool $signatureValid = true;

    private bool $loseCreateResponse = false;

    private bool $loseCaptureResponse = false;

    private int $createCalls = 0;

    private int $captureCalls = 0;

    private string $guestToken;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        config([
            'paypal.mode' => 'sandbox',
            'paypal.client_id' => 'test-client',
            'paypal.client_secret' => 'test-secret',
            'paypal.merchant_id' => 'TESTMERCHANT',
            'paypal.webhook_id' => 'TESTWEBHOOK',
            'paypal.return_url' => 'https://emtedad.example/api/v1/payments/paypal/return',
            'paypal.cancel_url' => 'https://emtedad.example/api/v1/payments/paypal/cancel',
        ]);
        $category = Category::create(['is_active' => true]);
        $this->case = HumanitarianCase::create([
            'category_id' => $category->id,
            'target_amount_minor' => 100000,
            'currency' => 'USD',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $this->guestToken = bin2hex(random_bytes(32));
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/oauth2/token') {
                return Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]);
            }
            if ($path === '/v1/notifications/verify-webhook-signature') {
                return Http::response(['verification_status' => $this->signatureValid ? 'SUCCESS' : 'FAILURE']);
            }
            if ($path === '/v2/checkout/orders' && $request->method() === 'POST') {
                $this->createCalls++;
                $key = $request->header('PayPal-Request-Id')[0];
                if (! isset($this->orders[$key])) {
                    $this->orders[$key] = $request->data() + [
                        'id' => 'ORDER'.(count($this->orders) + 1),
                        'status' => 'PAYER_ACTION_REQUIRED',
                        'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=TEST']],
                    ];
                }
                if ($this->loseCreateResponse) {
                    $this->loseCreateResponse = false;

                    return Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500);
                }

                return Http::response($this->orders[$key], 201);
            }
            if (preg_match('#^/v2/checkout/orders/(ORDER[0-9]+)(/capture)?$#', $path, $m)) {
                foreach ($this->orders as &$order) {
                    if ($order['id'] !== $m[1]) {
                        continue;
                    }
                    if ($request->method() === 'POST') {
                        $this->captureCalls++;
                        $order['status'] = 'COMPLETED';
                        $order['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE'.$m[1], 'status' => $this->captureState]];
                        if ($this->loseCaptureResponse) {
                            $this->loseCaptureResponse = false;

                            return Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500);
                        }
                    }

                    return Http::response($order);
                }
            }
            if (preg_match('#^/v2/payments/captures/(CAPTUREORDER[0-9]+)$#', $path, $m)) {
                foreach ($this->orders as $order) {
                    if ('CAPTURE'.$order['id'] === $m[1]) {
                        $unit = $order['purchase_units'][0];

                        return Http::response([
                            'id' => $m[1],
                            'status' => $this->captureState,
                            'amount' => ['value' => $this->wrongAmount ?? $unit['amount']['value'], 'currency_code' => $this->wrongCurrency ?? $unit['amount']['currency_code']],
                            'custom_id' => $unit['custom_id'],
                            'invoice_id' => $unit['invoice_id'],
                            'payee' => $unit['payee'],
                            'supplementary_data' => ['related_ids' => ['order_id' => $order['id']]],
                        ]);
                    }
                }
            }
            if (preg_match('#^/v2/payments/refunds/([^/]+)$#', $path, $m) && isset($this->refunds[$m[1]])) {
                return Http::response($this->refunds[$m[1]]);
            }
            throw new \RuntimeException('Unexpected fake endpoint: '.$request->method().' '.$path);
        });
    }

    private function body(): array
    {
        return [
            'case_public_id' => $this->case->public_id,
            'amount_minor' => 1250,
            'donor_name' => 'Donor',
            'donor_email' => 'donor@example.test',
        ];
    }

    private function checkout(?string $key = null, ?array $body = null, ?string $token = null): TestResponse
    {
        app('auth')->forgetGuards();
        $headers = ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Accept-Language' => 'en'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
            $uri = 'donations/paypal';
        } else {
            $headers['X-Donation-Token'] = $this->guestToken;
            $uri = 'guest/donations/paypal';
        }

        return $this->postJson('/api/v1/'.$uri, $body ?? $this->body(), $headers);
    }

    private function start(?string $userToken = null): Payment
    {
        $this->checkout(token: $userToken)->assertOk();

        return Payment::latest('id')->firstOrFail();
    }

    private function approve(Payment $payment): void
    {
        $this->orders[$payment->idempotency_key]['status'] = 'APPROVED';
    }

    private function capture(Payment $payment): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/guest/donations/'.$payment->donation->public_id.'/paypal/capture', [], ['X-Donation-Token' => $this->guestToken]);
    }

    private function completed(): Payment
    {
        $payment = $this->start();
        $this->approve($payment);
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'completed');

        return $payment->refresh();
    }

    private function webhook(string $eventId, string $type, string $resourceId): PaymentWebhook
    {
        return PaymentWebhook::create([
            'provider' => 'paypal',
            'event_id' => $eventId,
            'event_type' => $type,
            'payload' => ['resource_id' => $resourceId, 'environment' => 'sandbox'],
            'status' => 'received',
        ]);
    }

    private function receive(string $eventId = 'WH-TEST'): TestResponse
    {
        $raw = '{"id":"'.$eventId.'","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAPTUREORDER1","note":"  untouched  "}}';

        return $this->call('POST', '/api/v1/webhooks/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-TEST',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-09-20T10:00:00Z',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'signed-test',
        ], $raw);
    }

    public function test_checkout_freezes_payload_uses_integer_money_and_hashes_guest_token(): void
    {
        $payment = $this->start();
        $this->assertSame(1250, $payment->amount_minor);
        $this->assertSame(hash('sha256', $this->guestToken), $payment->donation->guest_token_hash);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '12.50' && $request['purchase_units'][0]['payee']['merchant_id'] === 'TESTMERCHANT' && $request['purchase_units'][0]['items'][0]['category'] === 'DONATION' && $request->hasHeader('PayPal-Request-Id', $payment->idempotency_key));
        $this->assertSame(1, Donation::count());
    }

    public function test_idempotent_creation_and_changed_payload_conflict(): void
    {
        $key = (string) Str::uuid();
        $a = $this->checkout($key)->assertOk()->json('data.public_id');
        $this->checkout($key)->assertOk()->assertJsonPath('data.public_id', $a);
        $this->checkout($key, array_replace($this->body(), ['amount_minor' => 1500]))->assertConflict();
        $this->assertSame(1, $this->createCalls);
        $this->assertDatabaseCount('donations', 1);
    }

    public function test_guest_cannot_reuse_another_guest_key_or_read_by_uuid_only(): void
    {
        $key = (string) Str::uuid();
        $id = $this->checkout($key)->assertOk()->json('data.public_id');
        $this->guestToken = bin2hex(random_bytes(32));
        $this->checkout($key)->assertConflict();
        $this->getJson('/api/v1/guest/donations/'.$id)->assertNotFound();
        $this->getJson('/api/v1/guest/donations/'.$id, ['X-Donation-Token' => $this->guestToken])->assertNotFound();
    }

    public function test_owner_access_is_scoped_and_admin_requires_permission(): void
    {
        $owner = $this->user();
        $token = $this->token($owner);
        $payment = $this->start($token);
        $id = $payment->donation->public_id;
        $this->api('GET', 'donations/'.$id, [], $token)->assertOk()->assertJsonMissingPath('data.payments.0.id')->assertJsonMissingPath('data.guest_token_hash');
        $this->api('GET', 'donations/'.$id, [], $this->token($this->user()))->assertNotFound();
        $this->api('GET', 'admin/donations', [], $token)->assertForbidden();
        $this->api('GET', 'admin/donations/'.$id, [], $this->token($this->user('admin')))->assertOk()->assertJsonPath('data.payments.0.id', $payment->id);
        $this->getJson('/api/v1/guest/donations/'.$id, ['X-Donation-Token' => $this->guestToken])->assertNotFound();
    }

    public function test_unauthenticated_unverified_and_suspended_accounts_are_rejected(): void
    {
        $this->api('POST', 'donations/paypal', $this->body())->assertUnauthorized();
        $this->checkout(token: $this->token($this->user('donor', false)))->assertForbidden();
        $user = $this->user();
        $user->forceFill(['status' => 'suspended'])->save();
        $this->checkout(token: $this->token($user))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_server_owned_fields_and_fractional_minor_amounts_are_rejected(): void
    {
        foreach ([['currency' => 'EUR'], ['status' => 'completed'], ['user_id' => 1], ['return_url' => 'https://evil.example'], ['amount_minor' => 12.5], ['amount_minor' => 0]] as $changes) {
            $this->checkout(body: array_replace($this->body(), $changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('donations', 0);
    }

    public function test_unavailable_cases_and_unsupported_currencies_are_rejected(): void
    {
        $this->case->update(['status' => 'paused']);
        $this->checkout()->assertUnprocessable();
        $this->case->update(['status' => 'published', 'ends_at' => now()->subMinute()]);
        $this->checkout()->assertUnprocessable();
        $this->case->update(['ends_at' => null]);
        config(['paypal.currencies' => ['EUR']]);
        $this->checkout()->assertUnprocessable();
        $this->assertDatabaseCount('donations', 0);
    }

    public function test_create_timeout_replays_same_key_and_same_frozen_payload(): void
    {
        $key = (string) Str::uuid();
        $this->loseCreateResponse = true;
        $this->checkout($key)->assertStatus(503);
        $this->assertDatabaseCount('donations', 1);
        config(['paypal.return_url' => 'https://changed.example/return']);
        $this->checkout($key)->assertOk();
        $this->assertSame(2, $this->createCalls);
        $this->assertCount(1, $this->orders);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders' && $request['payment_source']['paypal']['experience_context']['return_url'] === 'https://emtedad.example/api/v1/payments/paypal/return');
    }

    public function test_unknown_creation_is_not_blindly_replayed_after_retry_window(): void
    {
        $key = (string) Str::uuid();
        $this->loseCreateResponse = true;
        $this->checkout($key)->assertStatus(503);
        Payment::first()->update(['create_started_at' => now()->subHours(6)]);
        $this->checkout($key)->assertConflict()->assertJsonPath('code', 'CREATE_OUTCOME_UNKNOWN');
        $this->assertSame(1, $this->createCalls);
        $this->assertTrue(Payment::first()->needs_review);
    }

    public function test_capture_requires_approval_and_is_idempotent_after_completion(): void
    {
        $payment = $this->start();
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, $this->captureCalls);
        $this->approve($payment);
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'completed');
        $paidAt = $payment->donation->fresh()->paid_at;
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, $this->captureCalls);
        $this->assertEquals($paidAt, $payment->donation->fresh()->paid_at);
        $this->assertNotSame($payment->idempotency_key, $payment->capture_request_id);
    }

    public function test_capture_timeout_recovers_without_a_second_capture(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $this->loseCaptureResponse = true;
        $this->capture($payment)->assertStatus(503);
        $this->assertSame('pending', $payment->donation->fresh()->status->value);
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, $this->captureCalls);
    }

    public function test_pending_capture_is_not_a_completed_donation(): void
    {
        $this->captureState = 'PENDING';
        $payment = $this->start();
        $this->approve($payment);
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.paid_at', null);
        $this->captureState = 'COMPLETED';
        $event = $this->webhook('WH-COMPLETED', 'PAYMENT.CAPTURE.COMPLETED', 'CAPTUREORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
        $this->assertSame(1, $this->captureCalls);
    }

    public function test_mismatched_amount_or_currency_never_marks_donation_paid(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $this->wrongAmount = '100.00';
        $this->capture($payment)->assertConflict()->assertJsonPath('code', 'CAPTURE_DATA_MISMATCH');
        $this->assertNull($payment->donation->fresh()->paid_at);
        $this->wrongAmount = null;
        $this->wrongCurrency = 'EUR';
        $this->capture($payment)->assertConflict();
        $this->assertTrue($payment->fresh()->needs_review);
    }

    public function test_merchant_or_reference_mismatch_stops_capture(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $this->orders[$payment->idempotency_key]['purchase_units'][0]['payee']['merchant_id'] = 'OTHER';
        $this->capture($payment)->assertConflict()->assertJsonPath('code', 'ORDER_DATA_MISMATCH');
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_paused_case_stops_new_capture_but_archived_case_still_records_existing_capture(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $this->case->update(['status' => 'paused']);
        $this->capture($payment)->assertUnprocessable();
        $this->assertSame(0, $this->captureCalls);
        $this->orders[$payment->idempotency_key]['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTUREORDER1']];
        $this->orders[$payment->idempotency_key]['status'] = 'COMPLETED';
        $this->case->delete();
        $this->capture($payment)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_browser_return_and_cancel_never_change_financial_state(): void
    {
        $payment = $this->completed();
        $this->getJson('/api/v1/payments/paypal/cancel?token=ORDER1')->assertOk()->assertJsonPath('data.payment_confirmed', false);
        $this->getJson('/api/v1/payments/paypal/return?token=ORDER1')->assertOk()->assertJsonPath('data.payment_confirmed', false);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
    }

    public function test_unsigned_or_invalidly_signed_webhooks_do_not_persist(): void
    {
        $this->postJson('/api/v1/webhooks/paypal', [
            'id' => 'WH-X',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'X'],
        ])->assertStatus(400);
        $this->signatureValid = false;
        $this->receive()->assertForbidden();
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_webhook_requires_configured_id(): void
    {
        config(['paypal.webhook_id' => null]);
        $this->receive()->assertStatus(503)->assertJsonPath('code', 'WEBHOOK_ID_MISSING');
        $this->assertDatabaseCount('payment_webhooks', 0);
    }

    public function test_signature_verification_preserves_raw_json_and_stores_only_ids(): void
    {
        $this->receive()->assertStatus(202);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature') && str_contains($request->body(), '"note":"  untouched  "') && $request['webhook_id'] === 'TESTWEBHOOK');
        $this->assertSame(['resource_id' => 'CAPTUREORDER1', 'environment' => 'sandbox'], PaymentWebhook::first()->payload);
        Queue::assertPushed(ProcessPayPalWebhook::class);
    }

    public function test_duplicate_delivery_and_processing_do_not_duplicate_payment(): void
    {
        $payment = $this->completed();
        $this->receive()->assertStatus(202);
        $this->receive()->assertStatus(202);
        $this->assertDatabaseCount('payment_webhooks', 1);
        $id = PaymentWebhook::first()->id;
        app(PayPalWebhookService::class)->process($id);
        app(PayPalWebhookService::class)->process($id);
        $this->assertSame(1, PaymentWebhook::first()->attempts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $this->captureCalls);
    }

    public function test_approved_webhook_captures_when_buyer_does_not_return(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $event = $this->webhook('WH-APPROVED', 'CHECKOUT.ORDER.APPROVED', 'ORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
        $this->assertSame('processed', $event->fresh()->status->value);
    }

    public function test_webhook_can_recover_order_binding_after_lost_create_response(): void
    {
        $this->loseCreateResponse = true;
        $this->checkout()->assertStatus(503);
        $payment = Payment::first();
        $this->assertNull($payment->provider_order_id);
        $this->approve($payment);
        $event = $this->webhook('WH-EARLY', 'CHECKOUT.ORDER.APPROVED', 'ORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('ORDER1', $payment->fresh()->provider_order_id);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
    }

    public function test_stale_pending_and_declined_events_cannot_regress_a_paid_donation(): void
    {
        $payment = $this->completed();
        $this->captureState = 'PENDING';
        $event = $this->webhook('WH-LATE', 'PAYMENT.CAPTURE.PENDING', 'CAPTUREORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
        $this->captureState = 'DECLINED';
        $event = $this->webhook('WH-LATE2', 'PAYMENT.CAPTURE.DECLINED', 'CAPTUREORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('completed', $payment->donation->fresh()->status->value);
    }

    private function refund(string $id, string $amount, string $total, string $status = 'COMPLETED'): void
    {
        $this->refunds[$id] = [
            'id' => $id,
            'status' => $status,
            'amount' => ['value' => $amount, 'currency_code' => 'USD'],
            'seller_payable_breakdown' => ['total_refunded_amount' => ['value' => $total, 'currency_code' => 'USD']],
            'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTUREORDER1']],
        ];
    }

    public function test_partial_refund_is_idempotent_and_out_of_order_totals_never_shrink(): void
    {
        $payment = $this->completed();
        $this->captureState = 'PARTIALLY_REFUNDED';
        $this->refund('REFUND2', '2.00', '5.00');
        $event = $this->webhook('WH-REF2', 'PAYMENT.CAPTURE.REFUNDED', 'REFUND2');
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame(500, $payment->fresh()->refunded_amount_minor);
        $this->refund('REFUND1', '3.00', '3.00');
        $event = $this->webhook('WH-REF1', 'PAYMENT.CAPTURE.REFUNDED', 'REFUND1');
        app(PayPalWebhookService::class)->process($event->id);
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame(500, $payment->fresh()->refunded_amount_minor);
        $this->assertSame('partially_refunded', $payment->donation->fresh()->status->value);
        $this->assertDatabaseCount('payment_refunds', 2);
    }

    public function test_full_refund_and_reversal_are_sticky_terminal_states(): void
    {
        $payment = $this->completed();
        $this->captureState = 'REFUNDED';
        app(PaymentService::class)->reconcile($payment);
        $this->assertSame(1250, $payment->fresh()->refunded_amount_minor);
        $this->captureState = 'COMPLETED';
        app(PaymentService::class)->reconcile($payment);
        $this->assertSame('refunded', $payment->donation->fresh()->status->value);
        $event = $this->webhook('WH-REVERSE', 'PAYMENT.CAPTURE.REVERSED', 'CAPTUREORDER1');
        app(PayPalWebhookService::class)->process($event->id);
        app(PaymentService::class)->reconcile($payment);
        $this->assertSame('reversed', $payment->donation->fresh()->status->value);
    }

    public function test_missing_refund_details_are_flagged_instead_of_fabricating_net_amount(): void
    {
        $payment = $this->completed();
        $this->captureState = 'PARTIALLY_REFUNDED';
        $this->capture($payment)->assertOk()->assertJsonPath('data.payments.0.recognized_amount_minor', null)->assertJsonPath('data.payments.0.needs_review', true);
    }

    public function test_verified_webhook_failure_is_saved_and_can_be_retried(): void
    {
        $payment = $this->completed();
        $this->wrongAmount = '9.99';
        $event = $this->webhook('WH-RETRY', 'PAYMENT.CAPTURE.COMPLETED', 'CAPTUREORDER1');
        try {
            app(PayPalWebhookService::class)->process($event->id);
            $this->fail('Expected mismatch');
        } catch (PayPalException $e) {
            $this->assertSame('CAPTURE_DATA_MISMATCH', $e->reason);
        }
        $this->assertSame('failed', $event->fresh()->status->value);
        $this->wrongAmount = null;
        app(PayPalWebhookService::class)->process($event->id);
        $this->assertSame('processed', $event->fresh()->status->value);
        $this->assertSame(2, $event->fresh()->attempts);
    }

    public function test_payment_lock_rejects_overlapping_work(): void
    {
        $payment = $this->start();
        $this->approve($payment);
        $lock = Cache::lock('payment:paypal:'.$payment->id, 300);
        $lock->get();
        try {
            $this->capture($payment)->assertConflict()->assertJsonPath('code', 'PAYMENT_BUSY');
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_recovery_command_queues_stale_pending_payments_and_failed_webhooks(): void
    {
        $payment = $this->start();
        $payment->update(['last_synced_at' => now()->subMinutes(20)]);
        $event = $this->webhook('WH-STALE', 'CHECKOUT.ORDER.APPROVED', 'ORDER1');
        $event->forceFill(['status' => 'failed', 'updated_at' => now()->subMinutes(20)])->save();
        $this->artisan('paypal:reconcile')->assertSuccessful();
        Queue::assertPushed(ReconcilePayPalPayment::class, fn ($job) => $job->paymentId === $payment->id);
        Queue::assertPushed(ProcessPayPalWebhook::class, fn ($job) => $job->webhookId === $event->id);
    }

    public function test_currency_conversion_is_exact_and_rejects_unsupported_precision(): void
    {
        $this->assertSame('12.50', Money::decimal(1250));
        $this->assertSame(1250, Money::minor('12.5'));
        $this->assertSame(1, Money::minor('0.01'));
        $this->expectException(\InvalidArgumentException::class);
        Money::minor('12.501');
    }
}
