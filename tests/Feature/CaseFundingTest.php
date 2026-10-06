<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use App\Models\Payment;
use App\Services\DonationService;
use App\Services\WebsiteSettingsService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Emtedad\EmtedadTestCase;

class CaseFundingTest extends EmtedadTestCase
{
    private HumanitarianCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paypal.mode' => 'sandbox']);
        $category = Category::create(['is_active' => true]);
        $category->translations()->create(['locale' => 'ar', 'name' => 'Category', 'slug' => 'category']);
        $this->case = HumanitarianCase::create(['category_id' => $category->id, 'target_amount_minor' => 100000, 'currency' => 'USD', 'status' => 'published', 'published_at' => now()->subDay(), 'is_featured' => true]);
        $this->case->translations()->create(['locale' => 'ar', 'title' => 'Case', 'slug' => 'case', 'story' => 'Story']);
        $this->case->translations()->create(['locale' => 'en', 'title' => 'Case', 'slug' => 'case', 'story' => 'Story']);
    }

    private function payment(array $override = []): Payment
    {
        $donation = Donation::create(['humanitarian_case_id' => $this->case->id, 'amount_minor' => 10000, 'currency' => 'USD']);

        return $donation->payments()->create(array_replace(['provider' => 'paypal', 'environment' => 'sandbox', 'idempotency_key' => (string) Str::uuid(), 'provider_transaction_id' => 'CAPTURE-'.Str::uuid(), 'amount_minor' => 10000, 'refunded_amount_minor' => 0, 'currency' => 'USD', 'status' => 'completed', 'paid_at' => now(), 'needs_review' => false], $override));
    }

    public function test_funding_counts_only_matching_confirmed_net_amounts_for_current_environment(): void
    {
        $this->payment();
        $this->payment(['status' => 'partially_refunded', 'refunded_amount_minor' => 2500]);
        $this->payment(['status' => 'refunded', 'refunded_amount_minor' => 10000]);
        $this->payment(['status' => 'reversed', 'refunded_amount_minor' => 2000]);
        foreach ([['environment' => 'live'], ['needs_review' => true], ['paid_at' => null], ['provider_transaction_id' => null], ['status' => 'pending'], ['amount_minor' => 9999], ['currency' => 'EUR'], ['refunded_amount_minor' => 20000], ['status' => 'completed', 'refunded_amount_minor' => 100]] as $override) {
            $this->payment($override);
        }
        foreach (['public/cases/'.$this->case->public_id, 'public/cases/by-slug/case'] as $path) {
            $this->api('GET', $path)->assertOk()->assertJsonPath('data.raised_amount_minor', 17500)->assertJsonPath('data.payment_environment', 'sandbox')->assertJsonPath('data.amount_basis', 'confirmed_net_before_provider_fees');
        }
        $this->api('GET', 'public/cases')->assertOk()->assertJsonPath('data.items.0.raised_amount_minor', 17500);
        $this->api('GET', 'public/home')->assertOk()->assertJsonPath('data.featured_cases.0.raised_amount_minor', 17500);
        config(['paypal.mode' => 'live']);
        $this->api('GET', 'public/cases/'.$this->case->public_id)->assertOk()->assertJsonPath('data.raised_amount_minor', 10000)->assertJsonPath('data.payment_environment', 'live');
    }

    public function test_global_donations_setting_closes_public_case_and_blocks_checkout(): void
    {
        $this->api('GET', 'public/cases/'.$this->case->public_id)->assertOk()->assertJsonPath('data.accepting_donations', true);
        app(WebsiteSettingsService::class)->save(['settings' => ['donations_enabled' => false]]);
        $this->api('GET', 'public/cases/'.$this->case->public_id)->assertOk()->assertJsonPath('data.accepting_donations', false);
        $this->expectException(HttpException::class);
        app(DonationService::class)->ensurePayable($this->case);
    }
}
