<?php

namespace Tests\Feature\Emtedad;

use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class AccessTest extends EmtedadTestCase
{
    public function test_donors_and_members_cannot_manage_users_or_roles(): void
    {
        foreach (['donor', 'member'] as $role) {
            $token = $this->token($this->user($role));
            $this->api('GET', 'admin/users', [], $token)->assertForbidden();
            $this->api('GET', 'admin/roles', [], $token)->assertForbidden();
        }
    }

    public function test_admin_can_create_member_who_must_verify_email(): void
    {
        $token = $this->token($this->user('admin'));
        $data = $this->registration(['roles' => ['member']]);
        $r = $this->api('POST', 'admin/users', $data, $token)->assertCreated()->assertJsonPath('data.email_verified_at', null)->assertJsonPath('data.roles.0', 'member');
        Notification::assertSentTo(User::find($r->json('data.id')), VerifyEmailCodeNotification::class);
    }

    public function test_custom_role_permission_changes_apply_to_existing_tokens(): void
    {
        $admin = $this->token($this->user('admin'));
        $member = $this->user('member');
        $token = $this->token($member);
        $id = $this->api('POST', 'admin/roles', ['name' => 'case_editor', 'permissions' => ['dashboard.view', 'cases.view']], $admin)->assertCreated()->json('data.id');
        $this->api('PATCH', 'admin/users/'.$member->id.'/roles', ['roles' => ['case_editor']], $admin)->assertOk();
        $this->api('GET', 'admin/access', [], $token)->assertOk();
        $this->api('PATCH', 'admin/roles/'.$id, ['permissions' => ['cases.view']], $admin)->assertOk();
        $this->api('GET', 'admin/access', [], $token)->assertForbidden();
    }

    public function test_management_permissions_alone_do_not_grant_admin_role(): void
    {
        $member = $this->user('member');
        $member->givePermissionTo(['users.manage', 'roles.manage']);
        $token = $this->token($member);
        $this->api('GET', 'admin/users', [], $token)->assertForbidden();
        $this->api('POST', 'admin/roles', ['name' => 'attempt', 'permissions' => []], $token)->assertForbidden();
    }

    public function test_suspending_member_revokes_tokens(): void
    {
        $admin = $this->token($this->user('admin'));
        $member = $this->user('member');
        $token = $this->token($member);
        $this->api('PATCH', 'admin/users/'.$member->id, ['status' => 'suspended'], $admin)->assertOk();
        $this->api('GET', 'auth/me', [], $token)->assertUnauthorized();
        $this->api('POST', 'auth/login', ['email' => $member->email, 'password' => 'StrongPassword123'])->assertForbidden();
    }

    public function test_admin_cannot_disable_self_or_remove_own_role(): void
    {
        $user = $this->user('admin');
        $token = $this->token($user);
        $this->api('PATCH', 'admin/users/'.$user->id, ['status' => 'suspended'], $token)->assertUnprocessable();
        $this->api('PATCH', 'admin/users/'.$user->id.'/roles', ['roles' => ['donor']], $token)->assertUnprocessable();
    }

    public function test_core_roles_are_protected(): void
    {
        $token = $this->token($this->user('admin'));
        foreach (['admin', 'donor'] as $name) {
            $id = Role::findByName($name, config('emtedad.permission_guard'))->id;
            $this->api('PATCH', 'admin/roles/'.$id, ['permissions' => []], $token)->assertUnprocessable();
            $this->api('DELETE', 'admin/roles/'.$id, [], $token)->assertUnprocessable();
        }
        $this->api('PATCH', 'admin/roles/'.Role::findByName('member', config('emtedad.permission_guard'))->id, ['name' => 'other'], $token)->assertUnprocessable();
    }

    public function test_in_use_role_and_unknown_permissions_are_rejected(): void
    {
        $admin = $this->token($this->user('admin'));
        $member = $this->user('member');
        $this->api('POST', 'admin/roles', ['name' => 'bad', 'permissions' => ['anything.manage']], $admin)->assertUnprocessable();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $member->syncRoles('editor');
        $this->api('DELETE', 'admin/roles/'.$role->id, [], $admin)->assertUnprocessable();
        $this->api('PATCH', 'admin/users/'.$member->id.'/roles', ['roles' => ['member']], $admin)->assertOk();
        $this->api('DELETE', 'admin/roles/'.$role->id, [], $admin)->assertOk();
    }

    public function test_user_list_is_paginated_and_searchable(): void
    {
        $admin = $this->token($this->user('admin'));
        $member = $this->user('member');
        $this->api('GET', 'admin/users?role=member&per_page=1', [], $admin)->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.users.0.id', $member->id);
        $this->api('GET', 'admin/users?per_page=101', [], $admin)->assertUnprocessable();
    }

    public function test_reseeding_preserves_customized_member_permissions(): void
    {
        Role::findByName('member', config('emtedad.permission_guard'))->syncPermissions(['cases.view']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(['cases.view'], Role::findByName('member', config('emtedad.permission_guard'))->permissions->pluck('name')->all());
    }

    public function test_admin_command_creates_verified_admin_without_default_password(): void
    {
        $this->artisan('emtedad:create-admin', ['--email' => 'admin@example.test', '--name' => 'Admin'])->expectsQuestion('Password', 'StrongPassword123')->expectsQuestion('Confirm password', 'StrongPassword123')->assertSuccessful();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->hasRole('admin'));
    }
}
