<?php

namespace App\Services;

use App\Enum\RoleEnum;
use App\Enum\UserStatusEnum;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create(Arr::only($data, ['name', 'email', 'phone', 'password']) + ['preferred_locale' => $data['preferred_locale'] ?? config('emtedad.default_locale')]);
            $user->assignRole(RoleEnum::DONOR->value);
            $this->verification->send($user);

            return $this->issueToken($user->refresh(), $data);
        });
    }

    public function login(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => __('api.invalid_credentials')]);
            }
            abort_unless($user->status === UserStatusEnum::ACTIVE, 403, __('api.account_suspended'));
            if (Hash::needsRehash($user->password)) {
                $user->password = $data['password'];
            }
            $user->last_login_at = now();
            $user->save();

            return $this->issueToken($user, $data);
        });
    }

    public function profile(User $user): User
    {
        return $user->loadMissing(User::ACCESS_RELATIONS);
    }

    public function updateProfile(User $user, array $data): User
    {
        $user->update(Arr::only($data, ['name', 'phone', 'preferred_locale']));

        return $this->profile($user->refresh());
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    private function issueToken(User $user, array $data): array
    {
        $minutes = config(($data['remember_me'] ?? false) ? 'emtedad.auth.remember_token_minutes' : 'emtedad.auth.token_minutes');
        $expiresAt = now()->addMinutes($minutes);
        $token = $user->createToken($data['device_name'] ?? 'web', ['*'], $expiresAt);

        return ['user' => $this->profile($user), 'access_token' => $token->plainTextToken, 'expires_at' => $expiresAt];
    }
}
