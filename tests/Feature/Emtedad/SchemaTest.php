<?php

namespace Tests\Feature\Emtedad;

use App\Enum\CaseStatusEnum;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use App\Models\PaymentWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class SchemaTest extends EmtedadTestCase
{
    private function humanitarianCase(): HumanitarianCase
    {
        return HumanitarianCase::create(['target_amount_minor' => 125050, 'currency' => 'USD', 'status' => 'draft']);
    }

    public function test_case_accepts_three_translations_and_exact_minor_units(): void
    {
        $case = $this->humanitarianCase();
        foreach (['ar', 'en', 'fr'] as $locale) {
            $case->translations()->create(['locale' => $locale, 'title' => 'Case', 'slug' => 'case-'.$locale, 'story' => 'Story']);
        }
        $this->assertCount(3, $case->translations);
        $this->assertTrue(Str::isUuid($case->public_id));
        $this->assertSame(125050, $case->target_amount_minor);
        $this->assertSame(CaseStatusEnum::DRAFT, $case->status);
    }

    public function test_duplicate_parent_locale_is_rejected_by_database(): void
    {
        $case = $this->humanitarianCase();
        $data = ['locale' => 'ar', 'title' => 'Case', 'slug' => 'first', 'story' => 'Story'];
        $case->translations()->create($data);
        $this->expectException(QueryException::class);
        $case->translations()->create(array_replace($data, ['slug' => 'second']));
    }

    public function test_category_deletion_preserves_case(): void
    {
        $category = Category::create(['is_active' => true]);
        $case = $this->humanitarianCase();
        $case->update(['category_id' => $category->id]);
        $category->forceDelete();
        $this->assertNull($case->fresh()->category_id);
    }

    public function test_case_with_donation_cannot_be_permanently_deleted(): void
    {
        $case = $this->humanitarianCase();
        Donation::create(['humanitarian_case_id' => $case->id, 'amount_minor' => 1000, 'currency' => 'USD']);
        $this->expectException(QueryException::class);
        $case->forceDelete();
    }

    public function test_webhook_events_are_unique_and_donor_identity_is_hidden(): void
    {
        $case = $this->humanitarianCase();
        $donation = Donation::create(['humanitarian_case_id' => $case->id, 'amount_minor' => 1000, 'currency' => 'USD', 'donor_email' => 'private@example.test', 'is_anonymous' => true]);
        $this->assertArrayNotHasKey('donor_email', $donation->toArray());
        $this->assertTrue(Str::isUuid($donation->public_id));
        $data = ['provider' => 'paypal', 'event_id' => 'evt_1', 'event_type' => 'payment.completed', 'payload' => []];
        PaymentWebhook::create($data);
        $this->expectException(QueryException::class);
        PaymentWebhook::create($data);
    }
}
