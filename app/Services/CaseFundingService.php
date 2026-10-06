<?php

namespace App\Services;

use App\Models\HumanitarianCase;
use Illuminate\Database\Eloquent\Collection;

class CaseFundingService
{
    public function __construct(private readonly DonationReportService $reports, private readonly WebsiteSettingsService $settings) {}

    /** @param Collection<int, HumanitarianCase> $cases */
    public function load(Collection $cases): void
    {
        if ($cases->isEmpty()) {
            return;
        }
        $amounts = $this->reports->confirmedCasePayments(config('paypal.mode'))
            ->join('humanitarian_cases as c', 'c.id', '=', 'd.humanitarian_case_id')
            ->whereIn('c.id', $cases->modelKeys())->whereColumn('p.currency', 'c.currency')
            ->select('c.id')
            ->selectRaw("SUM(CASE WHEN p.status IN ('refunded', 'reversed') THEN 0 ELSE p.amount_minor - p.refunded_amount_minor END) AS raised_amount_minor")
            ->groupBy('c.id')->get()->keyBy('id');
        $enabled = $this->settings->acceptsDonations();
        foreach ($cases as $case) {
            $case->setAttribute('raised_amount_minor', (int) ($amounts->get($case->id)?->raised_amount_minor ?? 0));
            $case->setAttribute('donations_enabled', $enabled);
        }
    }
}
