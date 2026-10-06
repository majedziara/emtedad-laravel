<?php

namespace App\Services;

use App\Enum\CaseStatusEnum;
use App\Enum\DonationStatusEnum;
use App\Enum\PaymentProviderEnum;
use App\Enum\PaymentStatusEnum;
use App\Exceptions\PayPalException;
use App\Helpers\Money;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DonationService
{
    public function __construct(private readonly PayPalService $paypal) {}

    public function create(array $data, ?User $user = null): Payment
    {
        $this->paypal->assertConfigured(true);
        $key = strtolower($data['idempotency_key']);
        $guestHash = $user ? null : hash('sha256', $data['guest_token']);
        $normalized = [
            'user_id' => $user?->id,
            'guest_hash' => $guestHash,
            'case_public_id' => strtolower($data['case_public_id']),
            'amount_minor' => (int) $data['amount_minor'],
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'message' => $data['message'] ?? null,
            'donor_name' => $data['donor_name'] ?? null,
            'donor_email' => isset($data['donor_email']) ? Str::lower($data['donor_email']) : null,
            'donor_phone' => $data['donor_phone'] ?? null,
        ];
        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        $lock = Cache::lock('donation:create:'.$key, 30);
        if (! $lock->get()) {
            throw new PayPalException('CHECKOUT_BUSY', 409);
        }
        try {
            return DB::transaction(function () use ($key, $fingerprint, $normalized, $user, $guestHash) {
                $existing = Payment::with('donation')->where('idempotency_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409, __('payments.idempotency_conflict'));
                    abort_unless($existing->environment === config('paypal.mode'), 409, __('payments.environment_conflict'));

                    return $existing;
                }
                $case = HumanitarianCase::where('public_id', $normalized['case_public_id'])->lockForUpdate()->firstOrFail();
                $this->ensurePayable($case);
                $donation = Donation::create([
                    'humanitarian_case_id' => $case->id,
                    'user_id' => $user?->id,
                    'guest_token_hash' => $guestHash,
                    'amount_minor' => $normalized['amount_minor'],
                    'currency' => $case->currency,
                    'status' => DonationStatusEnum::PENDING,
                    'is_anonymous' => $normalized['is_anonymous'],
                    'message' => $normalized['message'],
                    'donor_name' => $normalized['donor_name'] ?? $user?->name,
                    'donor_email' => $normalized['donor_email'] ?? $user?->email,
                    'donor_phone' => $normalized['donor_phone'],
                ]);
                $payment = $donation->payments()->create([
                    'provider' => PaymentProviderEnum::PAYPAL,
                    'environment' => config('paypal.mode'),
                    'idempotency_key' => $key,
                    'capture_request_id' => (string) Str::uuid(),
                    'request_fingerprint' => $fingerprint,
                    'amount_minor' => $donation->amount_minor,
                    'currency' => $donation->currency,
                    'status' => PaymentStatusEnum::PENDING,
                    'metadata' => ['create_payload' => $this->payload($donation)],
                ]);

                return $payment->load('donation');
            });
        } finally {
            $lock->release();
        }
    }

    public function ensurePayable(HumanitarianCase $case): void
    {
        abort_unless(app(WebsiteSettingsService::class)->acceptsDonations(), 422, __('payments.case_not_accepting'));
        $active = ! $case->trashed() && $case->status === CaseStatusEnum::PUBLISHED && $case->published_at && $case->published_at->lte(now()) && (! $case->starts_at || $case->starts_at->lte(now())) && (! $case->ends_at || $case->ends_at->isFuture()) && $case->category()->where('is_active', true)->exists();
        abort_unless($active, 422, __('payments.case_not_accepting'));
        abort_unless(in_array($case->currency->value, config('paypal.currencies'), true), 422, __('payments.unsupported_currency'));
    }

    public function owned(string $publicId, User $user): Donation
    {
        return Donation::with(['payments.refunds', 'humanitarianCase.translations'])->where('public_id', $publicId)->where('user_id', $user->id)->firstOrFail();
    }

    public function guest(string $publicId, ?string $token): Donation
    {
        abort_unless(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token), 404);
        $donation = Donation::with(['payments.refunds', 'humanitarianCase.translations'])->where('public_id', $publicId)->whereNull('user_id')->firstOrFail();
        abort_unless($donation->guest_token_hash && hash_equals($donation->guest_token_hash, hash('sha256', $token)), 404);

        return $donation;
    }

    public function index(array $filters, ?User $owner = null): LengthAwarePaginator
    {
        $query = Donation::with(['payments.refunds', 'humanitarianCase.translations'])->latest('id');
        if ($owner) {
            $query->where('user_id', $owner->id);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['case_public_id'])) {
            $query->whereHas('humanitarianCase', fn ($q) => $q->where('public_id', $filters['case_public_id']));
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    private function payload(Donation $donation): array
    {
        $money = ['currency_code' => $donation->currency->value, 'value' => Money::decimal($donation->amount_minor)];

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $donation->public_id,
                'custom_id' => $donation->public_id,
                'invoice_id' => 'EMT-'.$donation->public_id,
                'description' => 'Charitable donation — Emtedad Charity Association',
                'payee' => ['merchant_id' => config('paypal.merchant_id')],
                'amount' => $money + ['breakdown' => ['item_total' => $money]],
                'items' => [[
                    'name' => 'Charitable donation',
                    'category' => 'DONATION',
                    'quantity' => '1',
                    'unit_amount' => $money,
                ]],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => config('paypal.brand_name'),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => config('paypal.return_url'),
                'cancel_url' => config('paypal.cancel_url'),
            ]]],
        ];
    }
}
