<?php

namespace Tests\Feature\Emtedad;

use App\Enum\UserStatusEnum;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class AuthTest extends EmtedadTestCase
{
    public function test_registration_creates_only_a_donor_and_hashes_code(): void
    {
        $response = $this->api('POST', 'auth/register', $this->registration(['email' => ' DONOR@EXAMPLE.TEST ']))->assertCreated()->assertJsonPath('data.user.roles.0', 'donor')->assertJsonMissingPath('data.code');
        $user = User::where('email', 'donor@example.test')->firstOrFail();
        $notice = Notification::sent($user, VerifyEmailCodeNotification::class)->first();
        $this->assertTrue(Hash::check($notice->code, $user->verificationCode->code_hash));
        $this->assertTrue(Hash::check('StrongPassword123', $user->password));
        $this->api('GET', 'auth/me', [], $response->json('data.access_token'))->assertOk();
    }

    public function test_registration_rejects_privilege_injection_and_weak_password(): void
    {
        $this->api('POST', 'auth/register', $this->registration(['roles' => ['admin']]))->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->api('POST', 'auth/register', $this->registration(['password' => 'weak', 'password_confirmation' => 'weak']))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $user = $this->user();
        $this->api('POST', 'auth/register', $this->registration(['email' => $user->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_issues_expiring_and_remembered_tokens(): void
    {
        $user = $this->user();
        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertOk();
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($user->tokens()->latest('id')->first()->expires_at), 1);
        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => 'StrongPassword123', 'remember_me' => true])->assertOk();
        $this->assertEqualsWithDelta(43200, now()->diffInMinutes($user->tokens()->latest('id')->first()->expires_at), 1);
    }

    public function test_invalid_credentials_and_suspension_block_login(): void
    {
        $this->api('POST', 'auth/login', ['email' => ['invalid'], 'password' => 'StrongPassword123'])->assertUnprocessable();
        $user = $this->user();
        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => 'WrongPassword123'])->assertUnprocessable();
        $user->forceFill(['status' => UserStatusEnum::SUSPENDED])->save();
        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertForbidden();
    }

    public function test_unverified_member_cannot_access_dashboard(): void
    {
        $user = $this->user('member', false);
        $this->api('GET', 'admin/access', [], $this->token($user))->assertForbidden();
    }

    public function test_verification_unlocks_member_access_and_is_idempotent(): void
    {
        $user = $this->user('member', false);
        $token = $this->token($user);
        app(EmailVerificationService::class)->send($user);
        $code = Notification::sent($user, VerifyEmailCodeNotification::class)->first()->code;
        $this->api('POST', 'auth/verify-email', ['code' => $code], $token)->assertOk();
        $this->api('GET', 'admin/access', [], $token)->assertOk();
        $this->api('POST', 'auth/verify-email', ['code' => $code], $token)->assertOk();
        $this->assertDatabaseCount('email_verification_codes', 0);
    }

    public function test_failed_code_attempts_persist_and_lock_the_code(): void
    {
        $user = $this->user('donor', false);
        $token = $this->token($user);
        app(EmailVerificationService::class)->send($user);
        $code = Notification::sent($user, VerifyEmailCodeNotification::class)->first()->code;
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', 'auth/verify-email', ['code' => '000000'], $token)->assertUnprocessable();
        }
        $this->assertSame(5, $user->verificationCode()->first()->attempts);
        $this->api('POST', 'auth/verify-email', ['code' => $code], $token)->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_code_is_rejected(): void
    {
        $user = $this->user('donor', false);
        $token = $this->token($user);
        app(EmailVerificationService::class)->send($user);
        $code = Notification::sent($user, VerifyEmailCodeNotification::class)->first()->code;
        $this->travel(11)->minutes();
        $this->api('POST', 'auth/verify-email', ['code' => $code], $token)->assertUnprocessable();
    }

    public function test_resend_has_cooldown_and_replaces_old_code(): void
    {
        $user = $this->user('donor', false);
        $token = $this->token($user);
        app(EmailVerificationService::class)->send($user);
        $old = Notification::sent($user, VerifyEmailCodeNotification::class)->first()->code;
        $this->api('POST', 'auth/resend-verification', [], $token)->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->api('POST', 'auth/resend-verification', [], $token)->assertOk();
        $new = Notification::sent($user, VerifyEmailCodeNotification::class)->last()->code;
        $this->assertNotSame($old, $new);
        $this->api('POST', 'auth/verify-email', ['code' => $old], $token)->assertUnprocessable();
        $this->api('POST', 'auth/verify-email', ['code' => $new], $token)->assertOk();
    }

    public function test_forgot_password_does_not_disclose_account_existence(): void
    {
        $user = $this->user();
        $a = $this->api('POST', 'auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $b = $this->api('POST', 'auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($a, $b);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_password_reset_revokes_all_tokens_and_cannot_be_reused(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $this->token($user);
        $reset = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $reset, 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'];
        $this->api('POST', 'auth/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('NewPassword12345', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->api('GET', 'auth/me', [], $token)->assertUnauthorized();
        $this->api('POST', 'auth/reset-password', $data)->assertUnprocessable();
    }

    public function test_expired_password_reset_is_rejected(): void
    {
        $user = $this->user();
        $reset = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->api('POST', 'auth/reset-password', ['email' => $user->email, 'token' => $reset, 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'])->assertUnprocessable();
    }

    public function test_change_password_requires_current_password_and_revokes_access(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $reset = Password::createToken($user);
        $data = ['current_password' => 'WrongPassword123', 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'];
        $this->api('PATCH', 'auth/password', $data, $token)->assertUnprocessable();
        $data['current_password'] = 'StrongPassword123';
        $this->api('PATCH', 'auth/password', $data, $token)->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse(Password::tokenExists($user, $reset));
    }

    public function test_logout_revokes_only_current_token_and_logout_all_revokes_others(): void
    {
        $user = $this->user();
        $a = $this->token($user);
        $b = $this->token($user);
        $this->api('POST', 'auth/logout', [], $a)->assertOk();
        $this->api('GET', 'auth/me', [], $a)->assertUnauthorized();
        $this->api('GET', 'auth/me', [], $b)->assertOk();
        $this->api('POST', 'auth/logout-all', [], $b)->assertOk();
        $this->api('GET', 'auth/me', [], $b)->assertUnauthorized();
    }

    public function test_expired_access_token_is_rejected(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $this->travel(61)->minutes();
        $this->api('GET', 'auth/me', [], $token)->assertUnauthorized();
    }

    public function test_profile_updates_cannot_escalate_privileges_or_change_email(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $this->api('PATCH', 'auth/profile', ['name' => 'Updated Name', 'preferred_locale' => 'ar'], $token)->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->api('PATCH', 'auth/profile', ['roles' => ['admin'], 'email' => 'other@example.test'], $token)->assertUnprocessable()->assertJsonValidationErrors(['roles', 'email']);
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', 'auth/login', ['email' => 'missing@example.test', 'password' => 'WrongPassword123'])->assertUnprocessable();
        }
        $this->api('POST', 'auth/login', ['email' => 'missing@example.test', 'password' => 'WrongPassword123'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_unauthenticated_api_returns_json_even_without_accept_header(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_supported_locales_can_expand_beyond_two(): void
    {
        config(['emtedad.locales' => ['ar', 'en', 'fr']]);
        $this->api('POST', 'auth/register', $this->registration(['preferred_locale' => 'fr']))->assertCreated()->assertJsonPath('data.user.preferred_locale', 'fr');
    }

    public function test_long_unicode_passwords_are_not_truncated(): void
    {
        $prefix = str_repeat('ع', 40).'Aa1';
        $password = $prefix.'Original';
        $user = User::factory()->create(['password' => $password]);
        $user->assignRole('donor');
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check($prefix.'Different', $user->password));
        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => $password])->assertOk();
    }
}
