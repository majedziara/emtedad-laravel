<?php

namespace Tests\Feature;

use App\Models\Partner;
use Database\Seeders\WebsiteSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Emtedad\EmtedadTestCase;

class WebsiteContentTest extends EmtedadTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('emtedad_private');
        $this->adminToken = $this->token($this->user('admin'));
    }

    private function section(array $override = []): array
    {
        return array_replace(['key' => 'hero', 'page' => 'home', 'is_active' => true, 'sort_order' => 1,
            'translations' => [['locale' => 'ar', 'title' => 'عنوان', 'items' => [['title' => 'عنصر', 'body' => 'نص']], 'button_url' => '/cases'], ['locale' => 'en', 'title' => 'Title', 'body' => 'Body']]], $override);
    }

    public function test_settings_defaults_translations_and_home_contract(): void
    {
        $this->api('GET', 'public/settings')->assertOk()->assertJsonPath('data.settings.donations_enabled', true)->assertJsonPath('data.available_locales', config('emtedad.locales'))->assertJsonPath('data.payment_environment', config('paypal.mode'));
        $this->seed(WebsiteSettingsSeeder::class);
        $this->api('GET', 'public/settings')->assertOk()->assertJsonPath('data.organization_name', 'Emtedad Charity Association');
        $this->api('PATCH', 'admin/settings', ['settings' => ['phone' => '+970123', 'donations_enabled' => false], 'translations' => [['locale' => 'ar', 'organization_name' => 'امتداد'], ['locale' => 'en', 'organization_name' => 'Emtedad']]], $this->adminToken)->assertOk()->assertJsonPath('data.settings.phone', '+970123');
        $this->api('GET', 'public/home')->assertOk()->assertJsonPath('data.settings.organization_name', 'Emtedad')->assertJsonPath('data.settings.settings.donations_enabled', false)->assertJsonPath('data.sections', [])->assertJsonPath('data.partners', [])->assertJsonPath('data.featured_cases', []);
        $this->api('PATCH', 'admin/settings', ['settings' => ['display_currencies' => ['USD', 'ILS']]], $this->adminToken)->assertOk()->assertJsonPath('data.settings.phone', '+970123');
        $this->api('DELETE', 'admin/settings/translations/ar', [], $this->adminToken)->assertUnprocessable();
        $this->api('DELETE', 'admin/settings/translations/en', [], $this->adminToken)->assertOk();
        $this->api('GET', 'public/settings')->assertOk()->assertJsonPath('data.locale', 'ar');
    }

    public function test_content_crud_items_localization_filters_and_visibility(): void
    {
        $id = $this->api('POST', 'admin/content-sections', $this->section(), $this->adminToken)->assertCreated()->json('data.id');
        $this->api('GET', 'public/content?website_page=home&per_page=1')->assertOk()->assertJsonPath('data.items.0.title', 'Title')->assertJsonPath('data.items.0.items', [])->assertJsonPath('data.pagination.total', 1);
        $this->api('GET', 'public/content?website_page=about')->assertOk()->assertJsonPath('data.items', []);
        $this->api('PATCH', 'admin/content-sections/'.$id, ['translations' => [['locale' => 'en', 'title' => 'Updated', 'items' => [['title' => 'Item', 'body' => null]]]]], $this->adminToken)->assertOk();
        $this->api('GET', 'public/home')->assertOk()->assertJsonPath('data.sections.0.items.0.title', 'Item');
        $this->api('DELETE', 'admin/content-sections/'.$id.'/translations/ar', [], $this->adminToken)->assertUnprocessable();
        $this->api('PATCH', 'admin/content-sections/'.$id, ['is_active' => false], $this->adminToken)->assertOk();
        $this->api('GET', 'public/content')->assertOk()->assertJsonPath('data.items', []);
        $this->api('GET', 'public/content/'.$id.'/image')->assertNotFound();
        $this->api('DELETE', 'admin/content-sections/'.$id, [], $this->adminToken)->assertOk();
        $this->assertDatabaseMissing('content_sections', ['id' => $id]);
        $this->assertDatabaseMissing('content_section_translations', ['content_section_id' => $id]);
    }

    public function test_partners_and_permissions(): void
    {
        $data = ['website_url' => 'https://example.org', 'is_active' => true, 'sort_order' => 5, 'translations' => [['locale' => 'ar', 'name' => 'شريك'], ['locale' => 'en', 'name' => 'Partner', 'description' => 'Description']]];
        $id = $this->api('POST', 'admin/partners', $data, $this->adminToken)->assertCreated()->json('data.id');
        $this->api('GET', 'public/partners')->assertOk()->assertJsonPath('data.items.0.name', 'Partner')->assertJsonPath('data.items.0.website_url', 'https://example.org');
        $member = $this->token($this->user('member'));
        foreach (['content-sections', 'partners', 'settings'] as $path) {
            $this->api('GET', 'admin/'.$path, [], $member)->assertForbidden();
            $this->api('GET', 'admin/'.$path)->assertUnauthorized();
        }
        $this->api('PATCH', 'admin/partners/'.$id, ['is_active' => false], $this->adminToken)->assertOk();
        $this->api('GET', 'public/partners')->assertOk()->assertJsonPath('data.items', []);
        $this->api('DELETE', 'admin/partners/'.$id, [], $this->adminToken)->assertOk();
        $this->assertDatabaseMissing('partner_translations', ['partner_id' => $id]);
    }

    public function test_plain_text_safe_urls_default_locale_and_item_limits_are_enforced(): void
    {
        foreach (['javascript:alert(1)', '//example.org', 'https://user:pass@example.org', '/bad\\path'] as $url) {
            $data = $this->section();
            $data['translations'][0]['button_url'] = $url;
            $this->api('POST', 'admin/content-sections', $data, $this->adminToken)->assertUnprocessable();
        }
        $this->api('POST', 'admin/content-sections', $this->section(['translations' => [['locale' => 'en', 'title' => 'Title']]]), $this->adminToken)->assertUnprocessable();
        $this->api('POST', 'admin/content-sections', $this->section(['translations' => [['locale' => 'ar', 'title' => '<script>bad</script>']]]), $this->adminToken)->assertUnprocessable();
        $data = $this->section();
        $data['translations'][0]['items'] = array_fill(0, 21, ['title' => 'Item']);
        $this->api('POST', 'admin/content-sections', $data, $this->adminToken)->assertUnprocessable();
        foreach (['javascript:alert(1)', 'https://user:pass@example.org'] as $url) {
            $this->api('PATCH', 'admin/settings', ['settings' => ['facebook_url' => $url]], $this->adminToken)->assertUnprocessable();
        }
        $this->api('PATCH', 'admin/settings', ['settings' => ['display_currencies' => []]], $this->adminToken)->assertUnprocessable();
    }

    public function test_images_are_served_replaced_deleted_and_preserved_when_settings_change(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1EAAAAASUVORK5CYII=');
        $id = $this->api('POST', 'admin/content-sections', $this->section(), $this->adminToken)->assertCreated()->json('data.id');
        $partner = $this->api('POST', 'admin/partners', ['is_active' => true, 'translations' => [['locale' => 'ar', 'name' => 'Partner']]], $this->adminToken)->assertCreated()->json('data.id');
        foreach (['content-sections/'.$id.'/image', 'partners/'.$partner.'/logo', 'settings/images/logo', 'settings/images/favicon'] as $path) {
            app('auth')->forgetGuards();
            $this->post('/api/v1/admin/'.$path, ['image' => UploadedFile::fake()->createWithContent('image.png', $bytes)], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->adminToken])->assertOk();
        }
        $this->api('GET', 'public/content/'.$id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->api('GET', 'public/partners/'.$partner.'/logo')->assertOk();
        $this->api('PATCH', 'admin/settings', ['settings' => ['phone' => '123']], $this->adminToken)->assertOk();
        $this->api('GET', 'public/settings/images/logo')->assertOk();
        $this->api('GET', 'public/settings/images/favicon')->assertOk();
        $this->api('DELETE', 'admin/settings/images/logo', [], $this->adminToken)->assertOk();
        $this->api('GET', 'public/settings/images/logo')->assertNotFound();
        $this->api('DELETE', 'admin/partners/'.$partner, [], $this->adminToken)->assertOk();
        $this->api('DELETE', 'admin/content-sections/'.$id, [], $this->adminToken)->assertOk();
        $this->assertCount(1, Storage::disk('emtedad_private')->allFiles());
    }

    public function test_partner_translation_falls_back_to_default_language_and_inactive_partners_stay_hidden(): void
    {
        $visible = Partner::factory()->active()->create();
        $visible->translations()->create(['locale' => config('emtedad.default_locale'), 'name' => 'Default partner']);
        Partner::factory()->translated()->create();
        $this->api('GET', 'public/partners')->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Default partner')->assertJsonPath('data.items.0.locale', config('emtedad.default_locale'));
    }
}
