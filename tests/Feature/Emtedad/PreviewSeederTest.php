<?php

declare(strict_types=1);

namespace Tests\Feature\Emtedad;

use App\Models\Category;
use App\Models\ContentSection;
use App\Models\HumanitarianCase;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\SettingTranslation;
use App\Models\User;
use App\Services\WebsiteSettingsService;
use Database\Seeders\Emtedad\CategoriesContentSeeder;
use Database\Seeders\Emtedad\SeedData;
use Database\Seeders\EmtedadPreviewSeeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PreviewSeederTest extends EmtedadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('emtedad_private');
    }

    public function test_preview_seed_installs_localized_content_and_images_without_fabricating_payments_or_users(): void
    {
        $this->seed(EmtedadPreviewSeeder::class);

        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('humanitarian_cases', 6);
        $this->assertDatabaseCount('content_sections', 10);
        $this->assertDatabaseCount('partners', 8);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('case_translations', 12);
        $this->assertDatabaseCount('content_section_translations', 20);
        $this->assertDatabaseCount('partner_translations', 16);
        $this->assertDatabaseCount('category_translations', 8);

        $marker = Setting::where('key', SeedData::VERSION)->firstOrFail();
        $this->assertFalse($marker->is_public);
        $this->assertTrue($marker->value['completed']);
        $this->assertFalse(SeedData::$running);
        $this->assertSame([], SeedData::$categories);
        $this->assertSame(0, Partner::where('is_active', true)->count());
        $this->assertFalse(ContentSection::where('key', 'privacy_policy')->firstOrFail()->is_active);
        $this->assertFalse(ContentSection::where('key', 'terms_of_use')->firstOrFail()->is_active);

        $disk = Storage::disk('emtedad_private');
        $paths = array_merge(Category::pluck('image_path')->all(), HumanitarianCase::pluck('cover_image_path')->all(), ContentSection::whereNotNull('image_path')->pluck('image_path')->all());
        $this->assertCount(13, $paths);
        $this->assertCount(13, array_unique($paths));
        $this->assertCount(13, $disk->allFiles());
        foreach ($paths as $path) {
            $disk->assertExists($path);
            $this->assertStringStartsWith('seed/emtedad-v1/', $path);
        }
        $this->assertSame(hash_file('sha256', resource_path('seeders/emtedad/assets/education.png')), hash('sha256', $disk->get('seed/emtedad-v1/categories/education.png')));

        foreach (['ar', 'en'] as $locale) {
            $this->getJson('/api/v1/public/home', ['Accept-Language' => $locale])->assertOk()
                ->assertJsonPath('data.settings.locale', $locale)
                ->assertJsonPath('data.settings.settings.donations_enabled', false)
                ->assertJsonCount(2, 'data.sections')->assertJsonCount(6, 'data.featured_cases')
                ->assertJsonPath('data.partners', []);
            $this->getJson('/api/v1/public/content?website_page=privacy', ['Accept-Language' => $locale])->assertOk()->assertJsonPath('data.items', []);
            foreach (HumanitarianCase::all() as $case) {
                $this->getJson('/api/v1/public/cases/'.$case->public_id, ['Accept-Language' => $locale])->assertOk()
                    ->assertJsonPath('data.locale', $locale)->assertJsonPath('data.raised_amount_minor', 0)
                    ->assertJsonPath('data.accepting_donations', false)->assertJsonPath('data.status', 'published');
            }
        }
        $case = HumanitarianCase::firstOrFail();
        $this->get('/api/v1/public/cases/'.$case->public_id.'/cover')->assertOk()->assertHeader('Content-Type', 'image/png');
        $hero = ContentSection::where('key', 'home_hero')->firstOrFail();
        $this->get('/api/v1/public/content/'.$hero->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_repeat_seed_preserves_dashboard_edits_deleted_rows_and_changed_images(): void
    {
        $this->seed(EmtedadPreviewSeeder::class);
        $hero = ContentSection::where('key', 'home_hero')->firstOrFail();
        $hero->translations()->where('locale', 'en')->update(['title' => 'Edited in dashboard']);
        $hero->update(['key' => 'renamed_hero', 'is_active' => false]);
        Storage::disk('emtedad_private')->put($hero->image_path, 'a replacement image');
        ContentSection::where('key', 'contact_intro')->firstOrFail()->delete();
        $case = HumanitarianCase::firstOrFail();
        $case->update(['status' => 'archived']);
        $case->delete();
        app(WebsiteSettingsService::class)->save(['settings' => ['phone' => 'Edited phone', 'donations_enabled' => true]]);
        $counts = [Partner::count(), Category::count(), HumanitarianCase::withTrashed()->count(), ContentSection::count()];
        $marker = Setting::where('key', SeedData::VERSION)->firstOrFail()->getRawOriginal();

        $this->seed(EmtedadPreviewSeeder::class);

        $this->assertSame($counts, [Partner::count(), Category::count(), HumanitarianCase::withTrashed()->count(), ContentSection::count()]);
        $this->assertDatabaseMissing('content_sections', ['key' => 'home_hero']);
        $this->assertDatabaseMissing('content_sections', ['key' => 'contact_intro']);
        $this->assertSame('Edited in dashboard', $hero->fresh()->translations()->where('locale', 'en')->value('title'));
        $this->assertSame('a replacement image', Storage::disk('emtedad_private')->get($hero->image_path));
        $this->assertTrue(HumanitarianCase::withTrashed()->findOrFail($case->id)->trashed());
        $this->assertSame('Edited phone', app(WebsiteSettingsService::class)->values(app(WebsiteSettingsService::class)->get())['phone']);
        $this->assertTrue(app(WebsiteSettingsService::class)->acceptsDonations());
        $this->assertSame($marker, Setting::where('key', SeedData::VERSION)->firstOrFail()->getRawOriginal());
    }

    public function test_repeat_seed_restores_lost_images_without_changing_database_content(): void
    {
        $this->seed(EmtedadPreviewSeeder::class);
        $disk = Storage::disk('emtedad_private');
        $hero = ContentSection::where('key', 'home_hero')->firstOrFail();
        $hero->update(['key' => 'renamed_hero']);
        $hero->translations()->where('locale', 'en')->update(['title' => 'Dashboard title']);
        app(WebsiteSettingsService::class)->save(['settings' => ['donations_enabled' => true]]);
        $heroData = $hero->fresh()->getRawOriginal();
        $case = HumanitarianCase::firstOrFail();
        $caseData = $case->getRawOriginal();
        $marker = Setting::where('key', SeedData::VERSION)->firstOrFail()->getRawOriginal();
        $paths = $disk->allFiles();
        $disk->delete($paths);
        $this->get('/api/v1/public/content/'.$hero->id.'/image')->assertNotFound();
        $this->get('/api/v1/public/cases/'.$case->public_id.'/cover')->assertNotFound();

        $this->seed(EmtedadPreviewSeeder::class);

        $this->assertCount(13, $disk->allFiles());
        foreach ($paths as $path) {
            $disk->assertExists($path);
        }
        $this->assertSame(hash_file('sha256', resource_path('seeders/emtedad/assets/'.SeedData::read('content-sections')[0]['asset'])), hash('sha256', $disk->get($hero->image_path)));
        $this->assertSame($heroData, $hero->fresh()->getRawOriginal());
        $this->assertSame($caseData, $case->fresh()->getRawOriginal());
        $this->assertSame($marker, Setting::where('key', SeedData::VERSION)->firstOrFail()->getRawOriginal());
        $this->assertSame('Dashboard title', $hero->fresh()->translations()->where('locale', 'en')->value('title'));
        $this->assertTrue(app(WebsiteSettingsService::class)->acceptsDonations());
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('humanitarian_cases', 6);
        $this->assertDatabaseCount('content_sections', 10);
        $this->assertDatabaseCount('partners', 8);
        $this->assertFalse(SeedData::$running);
        $this->get('/api/v1/public/content/'.$hero->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/public/cases/'.$case->public_id.'/cover')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_image_recovery_preserves_replacements_and_intentionally_removed_images(): void
    {
        $this->seed(EmtedadPreviewSeeder::class);
        $disk = Storage::disk('emtedad_private');
        $disk->delete($disk->allFiles());
        $hero = ContentSection::where('key', 'home_hero')->firstOrFail();
        $heroPath = $hero->image_path;
        $hero->update(['image_path' => 'content/dashboard-upload.png']);
        $category = Category::firstOrFail();
        $categoryPath = $category->image_path;
        $category->update(['image_path' => null]);
        $deletedCase = HumanitarianCase::firstOrFail();
        $deletedCover = $deletedCase->cover_image_path;
        $deletedCase->delete();
        $case = HumanitarianCase::firstOrFail();
        $disk->put($case->cover_image_path, 'Dashboard replacement');

        $this->seed(EmtedadPreviewSeeder::class);

        $disk->assertMissing([$heroPath, $categoryPath, $deletedCover, 'content/dashboard-upload.png']);
        $this->assertCount(10, $disk->allFiles());
        $this->assertSame('Dashboard replacement', $disk->get($case->cover_image_path));
        $this->assertSame('content/dashboard-upload.png', $hero->fresh()->image_path);
        $this->assertNull($category->fresh()->image_path);
        $this->assertTrue(HumanitarianCase::withTrashed()->findOrFail($deletedCase->id)->trashed());
    }

    public function test_first_run_preserves_existing_contact_addresses_and_partner_identity(): void
    {
        $setting = Setting::factory()->create(['key' => config('website.settings_key'), 'value' => array_replace(config('website.defaults'), [
            'contact_email' => 'contact@example.test', 'phone' => '+970123456', 'facebook_url' => 'https://example.test/contact',
            '_images' => ['logo' => 'website/original-logo.png', 'favicon' => 'website/original-icon.png'],
        ])]);
        foreach (['ar', 'en'] as $locale) {
            SettingTranslation::factory()->for($setting)->create(['locale' => $locale, 'address' => 'Existing detailed address '.$locale]);
        }
        $partner = Partner::factory()->active()->translated()->create(['sort_order' => 901, 'logo_path' => 'partners/original-logo.png', 'website_url' => 'https://example.test/partner']);
        $partner->translations()->where('locale', 'en')->update(['name' => SeedData::read('partners')[0]['translations'][1]['name'], 'description' => 'Existing partnership description']);
        $partnerData = $partner->fresh()->getRawOriginal();
        $user = User::factory()->create();

        $this->seed(EmtedadPreviewSeeder::class);

        $this->assertSame($partnerData, $partner->fresh()->getRawOriginal());
        $this->assertSame('Existing partnership description', $partner->fresh()->translations()->where('locale', 'en')->value('description'));
        $this->assertDatabaseCount('partners', 8);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $value = $setting->fresh()->value;
        $this->assertSame('contact@example.test', $value['contact_email']);
        $this->assertSame('+970123456', $value['phone']);
        $this->assertSame('https://example.test/contact', $value['facebook_url']);
        $this->assertSame(['logo' => 'website/original-logo.png', 'favicon' => 'website/original-icon.png'], $value['_images']);
        $this->assertFalse($value['donations_enabled']);
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('Existing detailed address '.$locale, $setting->fresh()->translations()->where('locale', $locale)->value('address'));
        }
    }

    public function test_failed_run_rolls_back_content_and_can_retry_after_resolving_inactive_category(): void
    {
        $row = SeedData::read('categories')[0];
        $token = $this->token($this->user('admin'));
        $id = $this->api('POST', 'admin/categories', ['is_active' => false, 'sort_order' => 77, 'translations' => $row['translations']], $token)->assertCreated()->json('data.id');
        try {
            $this->seed(EmtedadPreviewSeeder::class);
            $this->fail('An inactive existing category must stop the seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('inactive', $exception->getMessage());
        }
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('content_sections', 0);
        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('humanitarian_cases', 0);
        $this->assertTrue(app(WebsiteSettingsService::class)->acceptsDonations());
        $this->assertFalse(Setting::where('key', SeedData::VERSION)->firstOrFail()->value['completed']);
        $this->assertFalse(SeedData::$running);
        $this->assertSame([], SeedData::$categories);

        $this->api('PATCH', 'admin/categories/'.$id, ['is_active' => true], $token)->assertOk();
        $this->seed(EmtedadPreviewSeeder::class);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('humanitarian_cases', 6);
        $this->assertDatabaseHas('humanitarian_cases', ['public_id' => SeedData::read('cases')[0]['public_id'], 'category_id' => $id]);
        $this->assertSame(77, Category::findOrFail($id)->sort_order);
    }

    public function test_changed_file_at_a_seed_destination_is_never_overwritten(): void
    {
        $path = 'seed/emtedad-v1/content/home_hero.png';
        Storage::disk('emtedad_private')->put($path, 'existing custom image');
        try {
            $this->seed(EmtedadPreviewSeeder::class);
            $this->fail('A different existing file must stop the seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }
        $this->assertSame('existing custom image', Storage::disk('emtedad_private')->get($path));
        $this->assertDatabaseCount('content_sections', 0);
        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('humanitarian_cases', 0);
        $this->assertFalse(Setting::where('key', SeedData::VERSION)->firstOrFail()->value['completed']);
        $this->assertFalse(SeedData::$running);
    }

    public function test_preflight_failure_does_not_write_any_rows(): void
    {
        config(['emtedad.locales' => ['ar']]);
        try {
            app(EmtedadPreviewSeeder::class)->run();
            $this->fail('A missing supported language must stop the seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ar and en', $exception->getMessage());
        }
        $this->assertDatabaseCount('settings', 0);
        $this->assertDatabaseCount('content_sections', 0);
        $this->assertCount(0, Storage::disk('emtedad_private')->allFiles());
    }

    public function test_subseeders_cannot_run_outside_the_master_seeder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EmtedadPreviewSeeder only');
        app(CategoriesContentSeeder::class)->run();
    }

    public function test_preview_data_is_rejected_in_production(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(EmtedadPreviewSeeder::class)->run();
            $this->fail('Preview content must not be seeded in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local/testing/staging only', $exception->getMessage());
        }
        $this->assertDatabaseCount('settings', 0);
        $this->assertDatabaseCount('content_sections', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
