<?php

namespace Tests\Feature\Emtedad;

use App\Models\CaseMedia;
use App\Models\Category;
use App\Models\Donation;
use App\Models\HumanitarianCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

class CatalogTest extends EmtedadTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('emtedad_private');
        $this->adminToken = $this->token($this->user('admin'));
    }

    private function categoryData(string $slug = 'medical'): array
    {
        return ['is_active' => true, 'translations' => [[
            'locale' => 'ar',
            'name' => 'دعم صحي',
            'slug' => $slug,
        ], [
            'locale' => 'en',
            'name' => 'Medical care',
            'slug' => $slug,
        ]]];
    }

    private function category(): Category
    {
        $id = $this->api('POST', 'admin/categories', $this->categoryData(), $this->adminToken)->assertCreated()->json('data.id');

        return Category::findOrFail($id);
    }

    private function caseData(int $categoryId): array
    {
        return [
            'category_id' => $categoryId,
            'target_amount_minor' => 100000,
            'currency' => 'USD',
            'beneficiaries_count' => 1,
            'translations' => [[
                'locale' => 'ar',
                'title' => 'دعم العلاج',
                'slug' => 'دعم-العلاج',
                'story' => 'وصف الحالة دون معلومات المستفيد الخاصة.',
            ], [
                'locale' => 'en',
                'title' => 'Medical support',
                'slug' => 'medical-support',
                'story' => 'A case story without private beneficiary details.',
            ]],
        ];
    }

    private function makeCase(?string $token = null): HumanitarianCase
    {
        $id = $this->api('POST', 'admin/cases', $this->caseData($this->category()->id), $token ?? $this->adminToken)->assertCreated()->json('data.id');

        return HumanitarianCase::findOrFail($id);
    }

    private function imageFile(string $name = 'cover.png'): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1EAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function upload(string $uri, UploadedFile $file, string $key = 'image', array $data = [], ?string $token = null): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->post('/api/v1/'.$uri, $data + [$key => $file], [
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
            'Authorization' => 'Bearer '.($token ?? $this->adminToken),
        ]);
    }

    private function publish(HumanitarianCase $case): void
    {
        $this->upload('admin/cases/'.$case->id.'/cover', $this->imageFile())->assertOk();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'published'], $this->adminToken)->assertOk();
    }

    public function test_category_crud_merges_translations_and_has_locale_fallback(): void
    {
        $category = $this->category();
        $this->api('PATCH', 'admin/categories/'.$category->id, ['translations' => [[
            'locale' => 'en',
            'name' => 'Updated',
            'slug' => 'updated',
        ]]], $this->adminToken)->assertOk();
        $this->assertSame(2, $category->translations()->count());
        $this->api('GET', 'public/categories/'.$category->id)->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->api('DELETE', 'admin/categories/'.$category->id.'/translations/en', [], $this->adminToken)->assertOk();
        $this->api('GET', 'public/categories/'.$category->id)->assertOk()->assertJsonPath('data.locale', 'ar');
        $this->api('DELETE', 'admin/categories/'.$category->id.'/translations/ar', [], $this->adminToken)->assertUnprocessable();
    }

    public function test_category_requires_default_locale_and_rejects_duplicate_slugs(): void
    {
        $this->api('POST', 'admin/categories', ['translations' => [[
            'locale' => 'en',
            'name' => 'Test',
            'slug' => 'test',
        ]]], $this->adminToken)->assertUnprocessable();
        $this->category();
        $this->api('POST', 'admin/categories', $this->categoryData(), $this->adminToken)->assertUnprocessable();
    }

    public function test_donor_and_member_cannot_manage_categories(): void
    {
        foreach (['donor', 'member'] as $role) {
            $this->api('POST', 'admin/categories', $this->categoryData(), $this->token($this->user($role)))->assertForbidden();
        }
        $this->api('GET', 'admin/categories', [], $this->token($this->user('member')))->assertOk();
    }

    public function test_category_soft_delete_restore_and_public_visibility(): void
    {
        $category = $this->category();
        $this->api('DELETE', 'admin/categories/'.$category->id, [], $this->adminToken)->assertOk();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->api('GET', 'public/categories/'.$category->id)->assertNotFound();
        $this->api('POST', 'admin/categories/'.$category->id.'/restore', [], $this->adminToken)->assertOk()->assertJsonPath('data.is_active', false);
        $this->api('GET', 'public/categories/'.$category->id)->assertNotFound();
    }

    public function test_category_with_cases_cannot_be_deleted(): void
    {
        $case = $this->makeCase();
        $this->api('DELETE', 'admin/categories/'.$case->category_id, [], $this->adminToken)->assertUnprocessable();
    }

    public function test_category_image_replacement_cleans_old_file(): void
    {
        $category = $this->category();
        $this->upload('admin/categories/'.$category->id.'/image', $this->imageFile())->assertOk();
        $old = $category->fresh()->image_path;
        Storage::disk('emtedad_private')->assertExists($old);
        $this->upload('admin/categories/'.$category->id.'/image', $this->imageFile())->assertOk();
        Storage::disk('emtedad_private')->assertMissing($old);
        $this->api('GET', 'public/categories/'.$category->id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->api('PATCH', 'admin/categories/'.$category->id, ['is_active' => false], $this->adminToken)->assertOk();
        $this->api('GET', 'public/categories/'.$category->id.'/image')->assertNotFound();
    }

    public function test_member_creates_draft_but_cannot_inject_publication_or_publish(): void
    {
        $member = $this->user('member');
        $token = $this->token($member);
        $category = $this->category();
        $data = $this->caseData($category->id);
        $this->api('POST', 'admin/cases', $data + ['status' => 'published'], $token)->assertUnprocessable();
        $r = $this->api('POST', 'admin/cases', $data, $token)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.created_by', $member->id);
        $this->api('PATCH', 'admin/cases/'.$r->json('data.id').'/status', ['status' => 'published'], $token)->assertForbidden();
        $this->api('GET', 'public/cases/'.$r->json('data.public_id'))->assertNotFound();
    }

    public function test_publication_requires_cover_and_active_category(): void
    {
        $case = $this->makeCase();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'published'], $this->adminToken)->assertUnprocessable();
        $this->upload('admin/cases/'.$case->id.'/cover', $this->imageFile())->assertOk();
        $this->api('PATCH', 'admin/categories/'.$case->category_id, ['is_active' => false], $this->adminToken)->assertOk();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'published'], $this->adminToken)->assertUnprocessable();
    }

    public function test_public_details_use_locale_and_never_expose_private_fields(): void
    {
        $case = $this->makeCase();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['beneficiary_reference' => 'PRIVATE-123'], $this->adminToken)->assertOk();
        $this->publish($case);
        $this->api('GET', 'public/cases/'.$case->public_id)->assertOk()->assertJsonPath('data.title', 'Medical support')->assertJsonPath('data.accepting_donations', true)->assertJsonMissingPath('data.beneficiary_reference')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.cover_image_path');
        $this->api('GET', 'public/cases/by-slug/medical-support')->assertOk();
        $this->api('GET', 'public/cases?status=draft')->assertUnprocessable();
    }

    public function test_editor_cannot_modify_published_content_or_cover_or_translation(): void
    {
        $case = $this->makeCase();
        $this->publish($case);
        $member = $this->token($this->user('member'));
        $this->api('PATCH', 'admin/cases/'.$case->id, ['is_featured' => true], $member)->assertForbidden();
        $this->upload('admin/cases/'.$case->id.'/cover', $this->imageFile(), 'image', [], $member)->assertForbidden();
        $this->api('DELETE', 'admin/cases/'.$case->id.'/translations/en', [], $member)->assertForbidden();
    }

    public function test_case_updates_preserve_omitted_languages_and_allow_third_locale(): void
    {
        config(['emtedad.locales' => ['ar', 'en', 'fr']]);
        $case = $this->makeCase();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['translations' => [[
            'locale' => 'fr',
            'title' => 'Aide',
            'slug' => 'aide',
            'story' => 'Description',
        ]]], $this->adminToken)->assertOk();
        $this->assertSame(3, $case->translations()->count());
        $this->api('PATCH', 'admin/cases/'.$case->id, ['translations' => [[
            'locale' => 'fr',
            'title' => 'Nouveau',
            'slug' => 'aide',
            'story' => 'Description',
        ]]], $this->adminToken)->assertOk();
        $this->api('DELETE', 'admin/cases/'.$case->id.'/translations/en', [], $this->adminToken)->assertOk();
        $this->api('DELETE', 'admin/cases/'.$case->id.'/translations/ar', [], $this->adminToken)->assertUnprocessable();
    }

    public function test_html_and_invalid_translation_shapes_are_rejected_atomically(): void
    {
        $category = $this->category();
        $data = $this->caseData($category->id);
        $data['translations'][0]['story'] = '<script>alert(1)</script>';
        $this->api('POST', 'admin/cases', $data, $this->adminToken)->assertUnprocessable();
        $data = $this->caseData($category->id);
        $data['translations'][0]['internal_flag'] = true;
        $this->api('POST', 'admin/cases', $data, $this->adminToken)->assertUnprocessable();
        $this->assertDatabaseCount('humanitarian_cases', 0);
    }

    public function test_dates_validate_against_existing_value_and_scheduled_cases_are_hidden(): void
    {
        $case = $this->makeCase();
        $start = now()->addDays(2)->format('Y-m-d\TH:i:sP');
        $end = now()->addDays(3)->format('Y-m-d\TH:i:sP');
        $this->api('PATCH', 'admin/cases/'.$case->id, ['starts_at' => $start, 'ends_at' => $end], $this->adminToken)->assertOk();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['starts_at' => $case->fresh()->starts_at->toISOString()], $this->adminToken)->assertOk();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['ends_at' => now()->addDay()->format('Y-m-d\TH:i:sP')], $this->adminToken)->assertUnprocessable();
        $this->publish($case);
        $this->api('GET', 'public/cases/'.$case->public_id)->assertNotFound();
        $this->travel(4)->days();
        $this->api('GET', 'public/cases/'.$case->public_id)->assertOk()->assertJsonPath('data.accepting_donations', false);
    }

    public function test_status_transitions_and_cover_removal_protect_published_content(): void
    {
        $case = $this->makeCase();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'completed'], $this->adminToken)->assertUnprocessable();
        $this->publish($case);
        $this->api('DELETE', 'admin/cases/'.$case->id.'/cover', [], $this->adminToken)->assertUnprocessable();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'paused'], $this->adminToken)->assertOk();
        $this->api('GET', 'public/cases/'.$case->public_id)->assertOk()->assertJsonPath('data.accepting_donations', false);
        $this->api('PATCH', 'admin/cases/'.$case->id.'/status', ['status' => 'completed'], $this->adminToken)->assertOk();
        $this->assertNotNull($case->fresh()->completed_at);
    }

    public function test_currency_and_target_are_locked_after_donation(): void
    {
        $case = $this->makeCase();
        Donation::create([
            'humanitarian_case_id' => $case->id,
            'amount_minor' => 1000,
            'currency' => 'USD',
        ]);
        $this->api('PATCH', 'admin/cases/'.$case->id, ['currency' => 'EUR'], $this->adminToken)->assertUnprocessable();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['target_amount_minor' => 90000], $this->adminToken)->assertUnprocessable();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['priority' => 'urgent'], $this->adminToken)->assertOk();
    }

    public function test_archiving_preserves_donations_and_restores_only_as_draft(): void
    {
        $case = $this->makeCase();
        $this->publish($case);
        Donation::create([
            'humanitarian_case_id' => $case->id,
            'amount_minor' => 1000,
            'currency' => 'USD',
        ]);
        $this->api('DELETE', 'admin/cases/'.$case->id, [], $this->adminToken)->assertOk();
        $this->assertSoftDeleted('humanitarian_cases', ['id' => $case->id]);
        $this->assertDatabaseCount('donations', 1);
        $this->api('GET', 'public/cases/'.$case->public_id)->assertNotFound();
        $this->api('GET', 'public/cases/'.$case->public_id.'/cover')->assertNotFound();
        $this->api('GET', 'admin/cases?trashed=only', [], $this->adminToken)->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->api('POST', 'admin/cases/'.$case->id.'/restore', [], $this->adminToken)->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.published_at', null);
    }

    public function test_private_files_require_permissions_and_never_appear_publicly(): void
    {
        $case = $this->makeCase();
        $this->publish($case);
        $member = $this->token($this->user('member'));
        $id = $this->upload('admin/cases/'.$case->id.'/media', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), 'file', ['visibility' => 'private'])->assertCreated()->json('data.id');
        $this->api('GET', 'admin/cases/'.$case->id.'/media', [], $member)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'admin/cases/'.$case->id.'/media/'.$id.'/download', [], $member)->assertForbidden();
        $this->api('GET', 'admin/cases/'.$case->id.'/media/'.$id.'/download', [], $this->adminToken)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->api('GET', 'public/cases/'.$case->public_id.'/media/'.$id)->assertNotFound();
        $this->api('GET', 'public/cases/'.$case->public_id)->assertOk()->assertJsonCount(0, 'data.media');
    }

    public function test_document_cannot_be_uploaded_or_reclassified_as_public(): void
    {
        $case = $this->makeCase();
        $this->upload('admin/cases/'.$case->id.'/media', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), 'file', ['visibility' => 'public'])->assertUnprocessable();
        $id = $this->upload('admin/cases/'.$case->id.'/media', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), 'file', ['visibility' => 'private'])->assertCreated()->json('data.id');
        $this->api('PATCH', 'admin/cases/'.$case->id.'/media/'.$id, ['visibility' => 'public'], $this->adminToken)->assertUnprocessable();
    }

    public function test_member_cannot_read_or_write_private_beneficiary_data(): void
    {
        $case = $this->makeCase();
        $this->api('PATCH', 'admin/cases/'.$case->id, ['beneficiary_reference' => 'SECRET'], $this->adminToken)->assertOk();
        $token = $this->token($this->user('member'));
        $this->api('GET', 'admin/cases/'.$case->id, [], $token)->assertOk()->assertJsonMissingPath('data.beneficiary_reference');
        $this->api('PATCH', 'admin/cases/'.$case->id, ['beneficiary_reference' => 'CHANGED'], $token)->assertForbidden();
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'private'], $token)->assertForbidden();
    }

    public function test_cross_case_attachment_access_is_rejected(): void
    {
        $case = $this->makeCase();
        $other = HumanitarianCase::create([
            'category_id' => $case->category_id,
            'target_amount_minor' => 1000,
            'currency' => 'USD',
        ]);
        $id = $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertCreated()->json('data.id');
        $this->api('GET', 'admin/cases/'.$other->id.'/media/'.$id.'/download', [], $this->adminToken)->assertNotFound();
        $this->api('PATCH', 'admin/cases/'.$other->id.'/media/'.$id, ['sort_order' => 5], $this->adminToken)->assertNotFound();
        $this->api('DELETE', 'admin/cases/'.$other->id.'/media/'.$id, [], $this->adminToken)->assertNotFound();
    }

    public function test_public_image_access_tracks_case_and_attachment_visibility(): void
    {
        $case = $this->makeCase();
        $id = $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertCreated()->json('data.id');
        $this->api('GET', 'public/cases/'.$case->public_id.'/media/'.$id)->assertNotFound();
        $this->publish($case);
        $this->api('GET', 'public/cases/'.$case->public_id.'/media/'.$id)->assertOk();
        $this->api('PATCH', 'admin/cases/'.$case->id.'/media/'.$id, ['visibility' => 'private'], $this->adminToken)->assertOk();
        $this->api('GET', 'public/cases/'.$case->public_id.'/media/'.$id)->assertNotFound();
    }

    public function test_media_deletion_removes_storage_and_database_record(): void
    {
        $case = $this->makeCase();
        $id = $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertCreated()->json('data.id');
        $path = CaseMedia::findOrFail($id)->path;
        $this->api('DELETE', 'admin/cases/'.$case->id.'/media/'.$id, [], $this->adminToken)->assertOk();
        Storage::disk('emtedad_private')->assertMissing($path);
        $this->assertDatabaseMissing('case_media', ['id' => $id]);
    }

    public function test_upload_limits_and_svg_rejection(): void
    {
        $case = $this->makeCase();
        $this->upload('admin/cases/'.$case->id.'/cover', UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertUnprocessable();
        $this->upload('admin/cases/'.$case->id.'/media', UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'), 'file', ['visibility' => 'private'])->assertUnprocessable();
        config(['emtedad.content.max_media_per_case' => 1]);
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertCreated();
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertUnprocessable();
    }

    public function test_public_filters_pagination_and_inactive_category_visibility(): void
    {
        $case = $this->makeCase();
        $this->publish($case);
        $this->api('PATCH', 'admin/cases/'.$case->id, ['priority' => 'urgent', 'is_featured' => true], $this->adminToken)->assertOk();
        $this->api('GET', 'public/cases?priority=urgent&is_featured=1&per_page=1')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonCount(1, 'data.items');
        $this->api('GET', 'public/cases?per_page=101')->assertUnprocessable();
        $this->api('PATCH', 'admin/categories/'.$case->category_id, ['is_active' => false], $this->adminToken)->assertOk();
        $this->api('GET', 'public/cases')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->api('GET', 'public/cases/'.$case->public_id)->assertNotFound();
    }

    public function test_private_write_requires_explicit_view_and_manage_permissions(): void
    {
        $case = $this->makeCase();
        $member = $this->user('member');
        $member->givePermissionTo('cases.documents.manage');
        $token = $this->token($member);
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'private'], $token)->assertForbidden();
        $member->givePermissionTo('cases.documents.view');
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'private'], $token)->assertCreated();
    }

    public function test_database_failure_removes_newly_uploaded_file(): void
    {
        $case = $this->makeCase();
        CaseMedia::creating(function () {
            throw new \RuntimeException('Simulated insert failure');
        });
        $this->upload('admin/cases/'.$case->id.'/media', $this->imageFile(), 'file', ['visibility' => 'public'])->assertStatus(500);
        $this->assertDatabaseCount('case_media', 0);
        $this->assertSame([], Storage::disk('emtedad_private')->allFiles());
    }
}
