<?php

namespace Tests\Feature\Emtedad;

use App\Models\Category;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class ReportsTest extends EmtedadTestCase
{
    private HumanitarianCase $case;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));
        config(['paypal.mode' => 'sandbox']);
        Http::preventStrayRequests();
        $this->adminToken = $this->token($this->user('admin'));
        $this->case = $this->newCase();
    }

    private function newCase(string $currency = 'USD'): HumanitarianCase
    {
        $category = Category::create(['is_active' => true]);
        $case = HumanitarianCase::create([
            'category_id' => $category->id, 'currency' => $currency,
            'target_amount_minor' => 100000, 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        $case->translations()->create(['locale' => 'ar', 'title' => 'حالة للاختبار', 'slug' => (string) Str::uuid(), 'story' => 'قصة']);

        return $case;
    }

    private function payment(array $overrides = [], array $donor = [], ?HumanitarianCase $case = null): Payment
    {
        $case ??= $this->case;
        $donation = Donation::create(array_replace([
            'humanitarian_case_id' => $case->id, 'amount_minor' => $overrides['amount_minor'] ?? 10000,
            'currency' => $overrides['currency'] ?? $case->currency->value, 'status' => 'completed',
            'donor_name' => 'Private Donor', 'donor_email' => 'private@example.test', 'is_anonymous' => true,
            'paid_at' => '2026-09-15 12:00:00',
        ], $donor));

        return $donation->payments()->create(array_replace([
            'provider' => 'paypal', 'environment' => 'sandbox', 'idempotency_key' => (string) Str::uuid(),
            'provider_order_id' => 'ORDER-'.Str::uuid(), 'provider_transaction_id' => 'CAPTURE-'.Str::uuid(),
            'amount_minor' => $donation->amount_minor, 'refunded_amount_minor' => 0, 'currency' => $donation->currency,
            'status' => 'completed', 'paid_at' => '2026-09-15 12:00:00', 'last_synced_at' => now(), 'needs_review' => false,
        ], $overrides));
    }

    private function report(string $path = 'dashboard', array $filters = [], ?string $token = null): TestResponse
    {
        return $this->api('GET', 'admin/'.$path.'?'.http_build_query(array_replace([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-22',
        ], $filters)), [], $token ?? $this->adminToken);
    }

    private function totals(TestResponse $response, string $currency = 'USD'): array
    {
        return collect($response->json('data.financials.totals_by_currency'))->firstWhere('currency', $currency);
    }

    public function test_totals_exclude_unconfirmed_and_review_payments_and_do_not_double_deduct_reversal(): void
    {
        $this->payment();
        $this->payment(['amount_minor' => 5000, 'status' => 'partially_refunded', 'refunded_amount_minor' => 1500]);
        $this->payment(['amount_minor' => 3000, 'status' => 'refunded', 'refunded_amount_minor' => 3000]);
        $this->payment(['amount_minor' => 4000, 'status' => 'reversed', 'refunded_amount_minor' => 1000]);
        $this->payment(['amount_minor' => 6000, 'needs_review' => true]);
        foreach (['pending', 'authorized', 'failed', 'cancelled'] as $status) {
            $this->payment(['status' => $status]);
        }
        $this->payment(['paid_at' => null]);
        $this->payment(['provider_transaction_id' => null]);
        $this->payment(['amount_minor' => 7000, 'currency' => 'ILS']);
        $response = $this->report()->assertOk()->assertJsonPath('data.financials.excluded_payment_count', 1);
        $totals = $this->totals($response);
        $this->assertSame(4, $totals['payment_count']);
        $this->assertSame(22000, $totals['gross_amount_minor']);
        $this->assertSame(5500, $totals['refunded_amount_minor']);
        $this->assertSame(3000, $totals['reversed_amount_minor']);
        $this->assertSame(13500, $totals['net_amount_minor']);
        $this->assertSame(7000, $this->totals($response, 'ILS')['net_amount_minor']);
        $this->assertSame(0, $this->totals($response, 'EUR')['net_amount_minor']);
    }

    public function test_sandbox_live_and_missing_environment_are_separate(): void
    {
        $this->payment();
        $this->payment(['environment' => 'live', 'amount_minor' => 20000]);
        $this->payment(['environment' => null, 'amount_minor' => 90000]);
        $this->assertSame(10000, $this->totals($this->report()->assertOk())['net_amount_minor']);
        $live = $this->report(filters: ['environment' => 'live'])->assertOk()->assertJsonPath('data.context.environment', 'live');
        $this->assertSame(20000, $this->totals($live)['net_amount_minor']);
        config(['paypal.mode' => 'live']);
        $this->assertSame(20000, $this->totals($this->report()->assertOk())['net_amount_minor']);
    }

    public function test_inconsistent_amounts_and_refund_states_are_excluded(): void
    {
        $this->payment(['amount_minor' => 999], ['amount_minor' => 1000]);
        $this->payment(['currency' => 'EUR'], ['currency' => 'USD']);
        $this->payment(['refunded_amount_minor' => 12000]);
        $this->payment(['refunded_amount_minor' => 1000]);
        $this->payment(['status' => 'partially_refunded', 'refunded_amount_minor' => 0]);
        $this->payment(['status' => 'refunded', 'refunded_amount_minor' => 9999]);
        $result = $this->report()->assertOk()->assertJsonPath('data.financials.excluded_payment_count', 6);
        $this->assertSame(0, $this->totals($result)['net_amount_minor']);
        $this->report('reports/donations')->assertJsonPath('data.donations.pagination.total', 0);
    }

    public function test_date_filter_uses_payment_paid_at_inclusive_days_in_utc(): void
    {
        $this->payment(['paid_at' => '2026-09-01 00:00:00']);
        $this->payment(['paid_at' => '2026-09-22 23:59:59']);
        $this->payment(['paid_at' => '2026-08-31 23:59:59']);
        $this->payment(['paid_at' => '2026-09-23 00:00:00']);
        $this->payment(['paid_at' => null], ['paid_at' => '2026-09-15 12:00:00']);
        $response = $this->report()->assertJsonPath('data.context.timezone', 'UTC');
        $this->assertSame(2, $this->totals($response)['payment_count']);
    }

    public function test_guest_donations_are_not_misreported_as_unique_people(): void
    {
        $user = $this->user();
        $first = $this->payment(donor: ['user_id' => $user->id]);
        $this->payment(donor: ['user_id' => $user->id]);
        $this->payment();
        $this->payment();
        $first->donation->payments()->create([
            'provider' => 'paypal', 'environment' => 'sandbox', 'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => 10000, 'currency' => 'USD', 'status' => 'failed',
        ]);
        $totals = $this->totals($this->report());
        $this->assertSame(4, $totals['donation_count']);
        $this->assertSame(4, $totals['payment_count']);
        $this->assertSame(1, $totals['registered_donor_count']);
        $this->assertSame(2, $totals['guest_donation_count']);
    }

    public function test_case_and_donation_filters_and_pagination_do_not_change_full_summary(): void
    {
        $this->payment();
        $this->payment();
        $other = $this->newCase();
        $this->payment(case: $other);
        $result = $this->report('reports/donations', ['case_public_id' => $this->case->public_id, 'per_page' => 1, 'page' => 2])->assertOk();
        $result->assertJsonCount(1, 'data.donations.items')->assertJsonPath('data.donations.pagination.total', 2)
            ->assertJsonPath('data.summary.totals_by_currency.0.payment_count', 2);
        $this->report('reports/donations', ['category_id' => $other->category_id])->assertJsonPath('data.donations.pagination.total', 1);
        $this->report('reports/donations', ['case_public_id' => (string) Str::uuid()])->assertJsonPath('data.donations.pagination.total', 0);
    }

    public function test_currency_status_and_donor_type_filters_apply_to_reports_and_series(): void
    {
        $this->payment();
        $this->payment(['currency' => 'EUR']);
        $this->payment(['status' => 'partially_refunded', 'refunded_amount_minor' => 500], ['user_id' => $this->user()->id]);
        $filters = ['currency' => 'USD', 'status' => 'partially_refunded', 'donor_type' => 'registered'];
        $this->report('reports/donations', $filters)->assertJsonPath('data.donations.pagination.total', 1)->assertJsonPath('data.summary.totals_by_currency.0.net_amount_minor', 9500);
        $series = $this->report('dashboard/donations', $filters)->assertOk()->json('data.series_by_currency');
        $this->assertCount(1, $series);
        $this->assertSame(9500, array_sum(array_column($series[0]['points'], 'net_amount_minor')));
    }

    public function test_series_zero_fills_days_and_respects_partial_months_and_monday_weeks(): void
    {
        $this->payment(['paid_at' => '2026-08-31 00:00:00']);
        $this->payment(['paid_at' => '2026-09-01 00:00:00', 'amount_minor' => 2500]);
        $filters = ['date_from' => '2026-08-31', 'date_to' => '2026-09-02', 'currency' => 'USD'];
        $daily = $this->report('dashboard/donations', $filters)->assertOk()->json('data.series_by_currency.0.points');
        $this->assertSame([10000, 2500, 0], array_column($daily, 'net_amount_minor'));
        $weekly = $this->report('dashboard/donations', $filters + ['interval' => 'week'])->assertOk();
        $weekly->assertJsonCount(1, 'data.series_by_currency.0.points')->assertJsonPath('data.series_by_currency.0.points.0.period_start', '2026-08-31')->assertJsonPath('data.series_by_currency.0.points.0.net_amount_minor', 12500);
        $monthly = $this->report('dashboard/donations', $filters + ['interval' => 'month'])->assertOk();
        $monthly->assertJsonCount(2, 'data.series_by_currency.0.points');
    }

    public function test_titles_and_multiple_translations_do_not_multiply_money(): void
    {
        $this->case->translations()->create(['locale' => 'en', 'title' => 'English case', 'slug' => 'english-case', 'story' => 'Story']);
        $this->case->translations()->create(['locale' => 'fr', 'title' => 'French case', 'slug' => 'french-case', 'story' => 'Story']);
        $this->payment();
        $this->payment(['currency' => 'EUR']);
        $result = $this->report('reports/cases')->assertOk()->assertJsonPath('data.pagination.total', 2);
        $this->assertSame(['EUR', 'USD'], array_column($result->json('data.items'), 'currency'));
        $this->assertSame([10000, 10000], array_column($result->json('data.items'), 'net_amount_minor'));
        $this->report('reports/donations')->assertJsonPath('data.donations.items.0.case_title', 'English case');
        $this->case->translations()->where('locale', 'en')->delete();
        $this->report('reports/donations')->assertJsonPath('data.donations.items.0.case_title', 'حالة للاختبار');
    }

    public function test_archived_and_soft_deleted_cases_keep_historical_financials(): void
    {
        $this->payment();
        $this->case->update(['status' => 'archived']);
        $this->case->delete();
        $response = $this->report()->assertOk()->assertJsonPath('data.cases_current.total', 0);
        $this->assertSame(10000, $this->totals($response)['net_amount_minor']);
        $this->report('reports/cases')->assertJsonPath('data.items.0.case_deleted', true)->assertJsonPath('data.items.0.case_status', 'archived');
    }

    public function test_dashboard_permission_does_not_grant_financial_or_donor_data(): void
    {
        $this->payment();
        $token = $this->token($this->user('member'));
        $this->report(token: $token)->assertOk()->assertJsonPath('data.can_view_financials', false)->assertJsonPath('data.financials', null)->assertDontSee('private@example.test');
        foreach (['dashboard/donations', 'reports/donations', 'reports/cases', 'reports/donations/export'] as $path) {
            $this->report($path, token: $token)->assertForbidden();
        }
    }

    public function test_anonymous_and_donor_accounts_cannot_access_dashboard_or_reports(): void
    {
        $token = $this->token($this->user());
        foreach (['dashboard', 'dashboard/donations', 'reports/donations', 'reports/cases', 'reports/donations/export'] as $path) {
            $this->api('GET', 'admin/'.$path)->assertUnauthorized();
            $this->report($path, token: $token)->assertForbidden();
        }
    }

    public function test_export_requires_both_view_and_export_permissions(): void
    {
        $member = $this->user('member');
        $member->givePermissionTo('donations.view');
        $token = $this->token($member);
        $this->report('reports/donations', token: $token)->assertOk();
        $this->report('reports/donations/export', token: $token)->assertForbidden();
        $member->revokePermissionTo('donations.view');
        $member->givePermissionTo('donations.export');
        $this->report('reports/donations/export', token: $token)->assertForbidden();
        $member->givePermissionTo('donations.view');
        $this->report('reports/donations/export', token: $token)->assertOk();
    }

    public function test_unverified_or_inactive_admin_cannot_read_financials(): void
    {
        $unverified = $this->user('admin', false);
        $this->report(token: $this->token($unverified))->assertForbidden();
        $inactive = $this->user('admin');
        $inactive->forceFill(['status' => 'suspended'])->save();
        $this->report(token: $this->token($inactive))->assertForbidden();
    }

    public function test_dashboard_aggregates_never_include_private_donor_identity(): void
    {
        $this->payment();
        $this->report()->assertDontSee('Private Donor')->assertDontSee('private@example.test');
        $this->report('reports/cases')->assertDontSee('Private Donor');
        $this->report('reports/donations')->assertJsonPath('data.donations.items.0.donor.name', 'Private Donor')
            ->assertJsonPath('data.donations.items.0.donor.is_anonymous', true)->assertDontSee('guest_token_hash')->assertDontSee('metadata');
    }

    public function test_csv_has_bom_exact_decimals_formula_protection_and_all_filtered_rows(): void
    {
        $this->case->translations()->update(['title' => '=HYPERLINK("https://example.test")']);
        $this->payment(['amount_minor' => 1234], ['donor_name' => "\t=1+1", 'donor_email' => '+formula@example.test']);
        $this->payment(['amount_minor' => 500]);
        $result = $this->report('reports/donations/export', ['per_page' => 1])->assertOk();
        $result->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $result->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $first = array_combine($header, fgetcsv($stream, escape: ''));
        $second = array_combine($header, fgetcsv($stream, escape: ''));
        $this->assertFalse(fgetcsv($stream, escape: ''));
        fclose($stream);
        $this->assertSame('12.34', $first['gross_amount']);
        $this->assertSame('5.00', $second['gross_amount']);
        $this->assertStringStartsWith("'=", $first['case_title']);
        $this->assertStringStartsWith("'\t=", $first['donor_name']);
        $this->assertStringStartsWith("'+", $first['donor_email']);
    }

    public function test_export_limit_fails_before_streaming_and_never_truncates(): void
    {
        config(['reports.max_export_rows' => 1]);
        $this->payment();
        $this->payment();
        $this->report('reports/donations/export')->assertUnprocessable()->assertJsonValidationErrors('export');
    }

    public function test_invalid_dates_intervals_currencies_and_status_are_rejected(): void
    {
        foreach ([
            ['date_from' => '2026-09-23'], ['date_from' => '2026-02-30'], ['date_to' => ['bad']],
            ['date_from' => '2025-01-01'], ['date_from' => ''], ['environment' => 'all'],
            ['interval' => 'quarter'], ['status' => 'pending'], ['currency' => 'XXX'],
            ['per_page' => 101], ['case_public_id' => 'broken'], ['donor_type' => 'everyone'],
        ] as $filters) {
            $this->report(filters: $filters)->assertUnprocessable();
        }
    }

    public function test_maximum_period_and_defaults_are_accepted_with_zero_results(): void
    {
        $response = $this->api('GET', 'admin/dashboard', [], $this->adminToken)->assertOk()
            ->assertJsonPath('data.context.date_from', '2026-08-24')->assertJsonPath('data.context.date_to', '2026-09-22');
        $this->assertSame(0, $this->totals($response)['payment_count']);
        $this->report(filters: ['date_from' => '2025-09-22', 'date_to' => '2026-09-22'])->assertOk();
    }

    public function test_report_reads_are_repeatable_and_make_no_provider_calls_or_financial_writes(): void
    {
        $this->payment();
        $before = DB::table('payments')->first();
        $first = $this->report()->json('data.financials');
        $second = $this->report()->json('data.financials');
        $this->assertSame($first, $second);
        $this->assertEquals($before, DB::table('payments')->first());
        Http::assertNothingSent();
    }

    public function test_later_refund_updates_old_paid_cohort_and_cache_does_not_hide_it(): void
    {
        $payment = $this->payment();
        $this->assertSame(10000, $this->totals($this->report())['net_amount_minor']);
        $payment->update(['status' => 'partially_refunded', 'refunded_amount_minor' => 2000]);
        $result = $this->report()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.context.refund_basis', 'current_state_of_payments_in_period');
        $this->assertSame(8000, $this->totals($result)['net_amount_minor']);
    }

    public function test_export_rate_limit_is_separate_from_reading_reports(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->report('reports/donations/export')->assertOk();
        }
        $this->report('reports/donations/export')->assertStatus(429);
        $this->report('reports/donations')->assertOk();
    }
}
