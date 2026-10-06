<?php

namespace App\Services;

use App\Enum\CurrencyEnum;
use App\Enum\PaymentProviderEnum;
use App\Enum\PaymentStatusEnum;
use App\Enum\ReportIntervalEnum;
use App\Helpers\Money;
use App\Models\CaseTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonationReportService
{
    public const CAPTURED_STATES = [
        PaymentStatusEnum::COMPLETED->value,
        PaymentStatusEnum::PARTIALLY_REFUNDED->value,
        PaymentStatusEnum::REFUNDED->value,
        PaymentStatusEnum::REVERSED->value,
    ];

    // A reversal removes only the remaining amount, so refunds are never deducted twice.
    private const REVERSED = "CASE WHEN p.status = 'reversed' THEN p.amount_minor - p.refunded_amount_minor ELSE 0 END";

    private const NET = "CASE WHEN p.status IN ('refunded', 'reversed') THEN 0 ELSE p.amount_minor - p.refunded_amount_minor END";

    private const MONEY_FIELDS = ['gross_amount_minor', 'refunded_amount_minor', 'reversed_amount_minor', 'net_amount_minor'];

    public function context(array $filters): array
    {
        return [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'timezone' => 'UTC',
            'date_basis' => 'payments.paid_at',
            'refund_basis' => 'current_state_of_payments_in_period',
            'net_basis' => 'before_provider_fees',
            'environment' => $filters['environment'],
            'filters' => array_intersect_key($filters, array_flip(['currency', 'case_public_id', 'category_id', 'status', 'donor_type'])),
            'generated_at' => CarbonImmutable::now('UTC')->toISOString(),
        ];
    }

    public function summary(array $filters): array
    {
        $query = $this->confirmed($filters);
        $totals = (clone $query)->select('p.currency')->selectRaw($this->aggregateSql())
            ->selectRaw('COUNT(DISTINCT d.id) AS donation_count, COUNT(DISTINCT d.user_id) AS registered_donor_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN d.user_id IS NULL THEN d.id END) AS guest_donation_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN '.self::NET.' > 0 THEN d.humanitarian_case_id END) AS supported_case_count')
            ->groupBy('p.currency')->get()->keyBy('currency');

        return [
            'totals_by_currency' => array_map(function (string $currency) use ($totals): array {
                $row = $totals->get($currency);
                $result = ['currency' => $currency] + $this->metrics($row);
                foreach (['donation_count', 'registered_donor_count', 'guest_donation_count', 'supported_case_count'] as $field) {
                    $result[$field] = (int) ($row?->{$field} ?? 0);
                }

                return $result;
            }, $this->currencies($filters)),
            // Money from these captured candidates is excluded, including their gross value.
            'excluded_payment_count' => $this->candidates($filters)->whereNotIn('p.id', (clone $query)->select('p.id'))->count(),
        ];
    }

    public function series(array $filters): array
    {
        $interval = ReportIntervalEnum::from($filters['interval']);
        $rows = $this->confirmed($filters)->select('p.currency')
            ->selectRaw('DATE(p.paid_at) AS paid_day')->selectRaw($this->aggregateSql())
            ->groupBy('p.currency')->groupByRaw('DATE(p.paid_at)')->orderBy('paid_day')->get();
        $start = CarbonImmutable::parse($filters['date_from'], 'UTC');
        $end = CarbonImmutable::parse($filters['date_to'], 'UTC');
        $series = [];
        foreach ($this->currencies($filters) as $currency) {
            $points = [];
            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $key = $this->bucket($date, $interval);
                $points[$key] ??= ['period_start' => $key] + $this->metrics(null);
            }
            foreach ($rows->where('currency', $currency) as $row) {
                $key = $this->bucket(CarbonImmutable::parse($row->paid_day, 'UTC'), $interval);
                foreach ($this->metrics($row) as $name => $value) {
                    $points[$key][$name] += $value;
                }
            }
            $series[] = ['currency' => $currency, 'points' => array_values($points)];
        }

        return ['interval' => $interval->value, 'series_by_currency' => $series];
    }

    public function donations(array $filters): LengthAwarePaginator
    {
        return $this->rows($filters)->orderByDesc('p.paid_at')->orderByDesc('p.id')
            ->paginate($filters['per_page'] ?? config('reports.per_page'));
    }

    public function cases(array $filters): LengthAwarePaginator
    {
        // Aggregate payments before joining case metadata: one result per case and currency.
        $amounts = $this->confirmed($filters)->select('d.humanitarian_case_id', 'p.currency')
            ->selectRaw($this->aggregateSql())->selectRaw('COUNT(DISTINCT d.id) AS donation_count')
            ->groupBy('d.humanitarian_case_id', 'p.currency');
        $query = DB::query()->fromSub($amounts, 'a')->join('humanitarian_cases as c', 'c.id', '=', 'a.humanitarian_case_id')
            ->select('c.id as case_id', 'c.public_id as case_public_id', 'c.status as case_status', 'c.deleted_at as case_deleted_at', 'c.currency as target_currency', 'c.target_amount_minor', 'a.*');
        $this->withTitle($query);
        $result = $query->orderBy('a.currency')->orderByDesc('a.net_amount_minor')->orderBy('c.id')
            ->paginate($filters['per_page'] ?? config('reports.per_page'));
        $result->through(fn ($row) => [
            'case_public_id' => $row->case_public_id,
            'case_title' => $row->case_title,
            'case_status' => $row->case_status,
            'case_deleted' => $row->case_deleted_at !== null,
            'target_currency' => $row->target_currency,
            'target_amount_minor' => (int) $row->target_amount_minor,
            'currency' => $row->currency,
            'donation_count' => (int) $row->donation_count,
        ] + $this->metrics($row));

        return $result;
    }

    public function exportRows(array $filters): Collection
    {
        // Fetch one bounded result before sending headers; never silently truncate a report.
        $rows = $this->rows($filters)->orderBy('p.paid_at')->orderBy('p.id')
            ->limit(config('reports.max_export_rows') + 1)->get();
        if ($rows->count() > config('reports.max_export_rows')) {
            throw ValidationException::withMessages(['export' => __('reports.export_too_large', ['rows' => config('reports.max_export_rows')])]);
        }

        return $rows;
    }

    public function writeCsv(Collection $rows, array $filters): void
    {
        $stream = fopen('php://output', 'w');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'payment_id',
            'donation_public_id',
            'case_public_id',
            'case_title',
            'donor_user_id',
            'donor_name',
            'donor_email',
            'is_anonymous',
            'environment',
            'capture_id',
            'status',
            'currency',
            'gross_amount',
            'refunded_amount',
            'reversed_amount',
            'net_before_fees',
            'paid_at_utc',
            'last_synced_at_utc',
            'period_from_utc',
            'period_to_utc',
            'refund_basis',
        ], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row->payment_id,
                $row->donation_public_id,
                $row->case_public_id,
                $this->csvText($row->case_title),
                $row->user_id,
                $this->csvText($row->donor_name),
                $this->csvText($row->donor_email),
                (int) $row->is_anonymous,
                $row->environment,
                $this->csvText($row->capture_id),
                $row->status,
                $row->currency,
                Money::decimal((int) $row->gross_amount_minor),
                Money::decimal((int) $row->refunded_amount_minor),
                Money::decimal((int) $row->reversed_amount_minor),
                Money::decimal((int) $row->net_amount_minor),
                $row->paid_at,
                $row->last_synced_at,
                $filters['date_from'],
                $filters['date_to'],
                'current_state_of_payments_in_period',
            ], ',', '"', '');
        }
        fclose($stream);
    }

    private function candidates(array $filters): Builder
    {
        $query = DB::table('payments as p')->join('donations as d', 'd.id', '=', 'p.donation_id')
            ->join('humanitarian_cases as c', 'c.id', '=', 'd.humanitarian_case_id')
            ->where('p.provider', PaymentProviderEnum::PAYPAL->value)->where('p.environment', $filters['environment'])
            ->whereIn('p.status', self::CAPTURED_STATES)->whereNotNull('p.provider_transaction_id')->where('p.provider_transaction_id', '<>', '')
            ->where('p.paid_at', '>=', CarbonImmutable::parse($filters['date_from'], 'UTC')->startOfDay())
            ->where('p.paid_at', '<', CarbonImmutable::parse($filters['date_to'], 'UTC')->addDay()->startOfDay());
        foreach (['currency' => 'p.currency', 'case_public_id' => 'c.public_id', 'category_id' => 'c.category_id', 'status' => 'p.status'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (isset($filters['donor_type'])) {
            $filters['donor_type'] === 'registered' ? $query->whereNotNull('d.user_id') : $query->whereNull('d.user_id');
        }

        return $query;
    }

    private function confirmed(array $filters): Builder
    {
        return $this->validateConfirmed($this->candidates($filters));
    }

    public function confirmedCasePayments(string $environment): Builder
    {
        return $this->validateConfirmed(DB::table('payments as p')
            ->join('donations as d', 'd.id', '=', 'p.donation_id')
            ->where('p.provider', PaymentProviderEnum::PAYPAL->value)
            ->where('p.environment', $environment)
            ->whereIn('p.status', self::CAPTURED_STATES)
            ->whereNotNull('p.paid_at')
            ->whereNotNull('p.provider_transaction_id')
            ->where('p.provider_transaction_id', '<>', ''));
    }

    private function validateConfirmed(Builder $query): Builder
    {
        return $query->where('p.needs_review', false)
            ->whereColumn('p.currency', 'd.currency')->whereIn('p.currency', array_column(CurrencyEnum::cases(), 'value'))
            ->whereColumn('p.amount_minor', 'd.amount_minor')->where('p.amount_minor', '>', 0)
            ->where('p.refunded_amount_minor', '>=', 0)->whereColumn('p.refunded_amount_minor', '<=', 'p.amount_minor')
            ->where(function (Builder $q): void {
                $q->where(fn (Builder $s) => $s->where('p.status', PaymentStatusEnum::COMPLETED->value)->where('p.refunded_amount_minor', 0))
                    ->orWhere(fn (Builder $s) => $s->where('p.status', PaymentStatusEnum::PARTIALLY_REFUNDED->value)->where('p.refunded_amount_minor', '>', 0)->whereColumn('p.refunded_amount_minor', '<', 'p.amount_minor'))
                    ->orWhere(fn (Builder $s) => $s->where('p.status', PaymentStatusEnum::REFUNDED->value)->whereColumn('p.refunded_amount_minor', 'p.amount_minor'))
                    ->orWhere('p.status', PaymentStatusEnum::REVERSED->value);
            });
    }

    private function rows(array $filters): Builder
    {
        $query = $this->confirmed($filters)->select([
            'p.id as payment_id',
            'd.public_id as donation_public_id',
            'c.public_id as case_public_id',
            'c.deleted_at as case_deleted_at',
            'd.user_id',
            'd.donor_name',
            'd.donor_email',
            'd.is_anonymous',
            'p.provider',
            'p.environment',
            'p.provider_transaction_id as capture_id',
            'p.status',
            'p.currency',
            'p.amount_minor as gross_amount_minor',
            'p.refunded_amount_minor',
            'p.paid_at',
            'p.last_synced_at',
        ])->selectRaw(self::REVERSED.' AS reversed_amount_minor, '.self::NET.' AS net_amount_minor');
        $this->withTitle($query);

        return $query;
    }

    private function withTitle(Builder $query): void
    {
        // The unique (case, locale) constraint prevents duplicate rows. No translation join multiplies money.
        $query->selectSub(CaseTranslation::query()->select('title')->whereColumn('humanitarian_case_id', 'c.id')
            ->orderByRaw('CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END', [app()->getLocale(), config('emtedad.default_locale')])
            ->orderBy('locale')->limit(1), 'case_title');
    }

    private function aggregateSql(): string
    {
        return 'COUNT(*) AS payment_count, COALESCE(SUM(p.amount_minor), 0) AS gross_amount_minor, '
            .'COALESCE(SUM(p.refunded_amount_minor), 0) AS refunded_amount_minor, '
            .'COALESCE(SUM('.self::REVERSED.'), 0) AS reversed_amount_minor, '
            .'COALESCE(SUM('.self::NET.'), 0) AS net_amount_minor';
    }

    private function metrics(?object $row): array
    {
        $result = ['payment_count' => (int) ($row?->payment_count ?? 0)];
        foreach (self::MONEY_FIELDS as $field) {
            $result[$field] = (int) ($row?->{$field} ?? 0);
        }

        return $result;
    }

    private function currencies(array $filters): array
    {
        return isset($filters['currency']) ? [$filters['currency']] : array_column(CurrencyEnum::cases(), 'value');
    }

    private function bucket(CarbonImmutable $day, ReportIntervalEnum $interval): string
    {
        return (match ($interval) {
            ReportIntervalEnum::DAY => $day,
            ReportIntervalEnum::WEEK => $day->startOfWeek(CarbonImmutable::MONDAY),
            ReportIntervalEnum::MONTH => $day->startOfMonth(),
        })->toDateString();
    }

    private function csvText(?string $value): string
    {
        $value ??= '';

        // Quoting CSV fields alone does not prevent spreadsheet formula execution.
        return preg_match('/^[\\s\\x{FEFF}]*[=+@\\-]/u', $value) || preg_match('/^[\\t\\r\\n]/', $value) ? "'".$value : $value;
    }
}
