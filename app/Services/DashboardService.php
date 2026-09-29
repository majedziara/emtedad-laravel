<?php

namespace App\Services;

use App\Enum\CaseStatusEnum;
use App\Models\HumanitarianCase;

class DashboardService
{
    public function __construct(private readonly DonationReportService $reports) {}

    public function overview(array $filters, bool $canViewMoney): array
    {
        $cases = HumanitarianCase::query();
        if (isset($filters['case_public_id'])) {
            $cases->where('public_id', $filters['case_public_id']);
        }
        if (isset($filters['category_id'])) {
            $cases->where('category_id', $filters['category_id']);
        }
        $counts = $cases->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $byStatus = [];
        foreach (CaseStatusEnum::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return [
            'context' => $this->reports->context($filters),
            // Operational snapshot: independent of the payment period, currency and donor filters.
            'cases_current' => ['total' => array_sum($byStatus), 'by_status' => $byStatus],
            'can_view_financials' => $canViewMoney,
            'financials' => $canViewMoney ? $this->reports->summary($filters) : null,
        ];
    }
}
