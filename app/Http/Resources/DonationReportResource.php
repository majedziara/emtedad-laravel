<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_id' => (int) $this->payment_id,
            'donation_public_id' => $this->donation_public_id,
            'case_public_id' => $this->case_public_id,
            'case_title' => $this->case_title,
            'case_deleted' => $this->case_deleted_at !== null,
            'donor' => [
                'user_id' => $this->user_id === null ? null : (int) $this->user_id,
                'name' => $this->donor_name,
                'email' => $this->donor_email,
                'is_anonymous' => (bool) $this->is_anonymous,
            ],
            'provider' => $this->provider,
            'environment' => $this->environment,
            'capture_id' => $this->capture_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'gross_amount_minor' => (int) $this->gross_amount_minor,
            'refunded_amount_minor' => (int) $this->refunded_amount_minor,
            'reversed_amount_minor' => (int) $this->reversed_amount_minor,
            'net_amount_minor' => (int) $this->net_amount_minor,
            'paid_at' => CarbonImmutable::parse($this->paid_at, 'UTC')->toISOString(),
            'last_synced_at' => $this->last_synced_at ? CarbonImmutable::parse($this->last_synced_at, 'UTC')->toISOString() : null,
        ];
    }
}
