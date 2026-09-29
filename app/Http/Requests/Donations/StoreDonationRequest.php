<?php

namespace App\Http\Requests\Donations;

use App\Http\Requests\ApiRequest;
use App\Rules\PlainText;

class StoreDonationRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        if ($this->routeIs('guest.donations.store')) {
            $this->merge(['guest_token' => $this->header('X-Donation-Token')]);
        }
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'guest_token' => [$this->routeIs('guest.donations.store') ? 'required' : 'prohibited', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'case_public_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:' . config('paypal.min_amount_minor'), 'max:' . config('paypal.max_amount_minor')],
            'is_anonymous' => ['sometimes', 'boolean'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:160', new PlainText],
            'donor_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A[+0-9 ()-]+\z/'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
            'user_id' => ['prohibited'],
            'provider_order_id' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'return_url' => ['prohibited'],
            'cancel_url' => ['prohibited'],
        ];
    }
}
