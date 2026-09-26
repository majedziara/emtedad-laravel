<?php

namespace App\Services;

use App\Enum\UserStatusEnum;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordService
{
    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email, 'status' => UserStatusEnum::ACTIVE->value]);
    }

    public function reset(array $data): void
    {
        $status = DB::transaction(function () use ($data) {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data + ['status' => UserStatusEnum::ACTIVE->value], function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                DB::afterCommit(fn() => event(new PasswordReset($user)));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => __('api.reset_invalid')]);
        }
    }

    public function change(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === UserStatusEnum::ACTIVE, 403, __('api.account_suspended'));
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => __('api.current_password_invalid')]);
            }
            $locked->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $locked->tokens()->delete();
            Password::broker()->getRepository()->delete($locked);
        });
    }
}
