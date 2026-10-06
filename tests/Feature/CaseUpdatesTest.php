<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\HumanitarianCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Emtedad\EmtedadTestCase;

class CaseUpdatesTest extends EmtedadTestCase
{
    private HumanitarianCase $case;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('emtedad_private');
        $this->adminToken = $this->token($this->user('admin'));
        $this->case = HumanitarianCase::create(['category_id' => Category::create(['is_active' => true])->id, 'target_amount_minor' => 100000, 'currency' => 'USD', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->case->translations()->create(['locale' => 'ar', 'title' => 'Case', 'slug' => 'case', 'story' => 'Story']);
    }

    private function data(string $status = 'draft'): array
    {
        return ['status' => $status, 'translations' => [['locale' => 'ar', 'title' => 'تحديث', 'body' => 'نص'], ['locale' => 'en', 'title' => 'Update', 'body' => 'Body']]];
    }

    private function base(): string
    {
        return 'admin/cases/'.$this->case->id.'/updates';
    }

    public function test_published_updates_are_paginated_and_drafts_and_future_updates_are_hidden(): void
    {
        $id = $this->api('POST', $this->base(), $this->data('published'), $this->adminToken)->assertCreated()->json('data.id');
        $this->api('POST', $this->base(), $this->data(), $this->adminToken)->assertCreated();
        $this->api('POST', $this->base(), $this->data('published') + ['published_at' => now()->addDay()->toISOString()], $this->adminToken)->assertCreated();
        $this->api('GET', 'public/cases/'.$this->case->public_id.'/updates?per_page=1')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.title', 'Update');
        $this->api('GET', $this->base(), [], $this->adminToken)->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->api('GET', $this->base().'?status=draft', [], $this->adminToken)->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->api('PATCH', $this->base().'/'.$id, ['status' => 'draft'], $this->adminToken)->assertOk()->assertJsonPath('data.published_at', null);
        $this->api('GET', 'public/cases/'.$this->case->public_id.'/updates')->assertOk()->assertJsonPath('data.items', []);
        $this->case->update(['status' => 'draft']);
        $this->api('GET', 'public/cases/'.$this->case->public_id.'/updates')->assertNotFound();
    }

    public function test_update_belongs_to_case_and_default_translation_is_protected(): void
    {
        $id = $this->api('POST', $this->base(), $this->data(), $this->adminToken)->assertCreated()->json('data.id');
        $other = $this->case->replicate();
        $other->public_id = null;
        $other->save();
        $base = 'admin/cases/'.$other->id.'/updates/'.$id;
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->api($method, $base, [], $this->adminToken)->assertNotFound();
        }
        $this->api('GET', $base.'/image', [], $this->adminToken)->assertNotFound();
        $this->api('DELETE', $this->base().'/'.$id.'/translations/ar', [], $this->adminToken)->assertUnprocessable();
        $this->api('DELETE', $this->base().'/'.$id.'/translations/en', [], $this->adminToken)->assertOk();
        $this->api('DELETE', $this->base().'/'.$id, [], $this->adminToken)->assertOk();
        $this->assertDatabaseMissing('case_update_translations', ['case_update_id' => $id]);
    }

    public function test_members_cannot_publish_or_change_published_updates_and_donors_cannot_read_admin_updates(): void
    {
        $this->case->update(['status' => 'draft']);
        $member = $this->token($this->user('member'));
        $id = $this->api('POST', $this->base(), $this->data(), $member)->assertCreated()->json('data.id');
        $this->api('PATCH', $this->base().'/'.$id, ['status' => 'published'], $member)->assertForbidden();
        $this->api('PATCH', $this->base().'/'.$id, ['status' => 'published'], $this->adminToken)->assertOk();
        $this->api('PATCH', $this->base().'/'.$id, ['status' => 'draft'], $member)->assertForbidden();
        $this->api('DELETE', $this->base().'/'.$id, [], $member)->assertForbidden();
        $this->api('GET', $this->base(), [], $this->token($this->user()))->assertForbidden();
    }

    public function test_update_images_follow_publication_and_are_removed_with_the_update(): void
    {
        $id = $this->api('POST', $this->base(), $this->data('published'), $this->adminToken)->assertCreated()->json('data.id');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1EAAAAASUVORK5CYII=');
        app('auth')->forgetGuards();
        $this->post('/api/v1/'.$this->base().'/'.$id.'/image', ['image' => UploadedFile::fake()->createWithContent('image.png', $bytes)], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->adminToken])->assertOk();
        $public = 'public/cases/'.$this->case->public_id.'/updates/'.$id.'/image';
        $this->api('GET', $public)->assertOk();
        $this->api('PATCH', $this->base().'/'.$id, ['status' => 'draft'], $this->adminToken)->assertOk();
        $this->api('GET', $public)->assertNotFound();
        $this->api('DELETE', $this->base().'/'.$id, [], $this->adminToken)->assertOk();
        $this->assertCount(0, Storage::disk('emtedad_private')->allFiles());
        $this->assertCount(0, Storage::disk('emtedad_images')->allFiles());
    }
}
