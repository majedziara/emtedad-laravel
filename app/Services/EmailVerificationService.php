<?php

namespace App\Services;

use App\Enum\UserStatusEnum;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public function send(User $user): void
    {
        DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === UserStatusEnum::ACTIVE, 403, __('api.account_suspended'));
            if ($locked->hasVerifiedEmail()) {
                return;
            }
            $previous = $locked->verificationCode()->first();
            if ($previous && $previous->sent_at->copy()->addSeconds(config('emtedad.auth.verification_resend_seconds'))->isFuture()) {
                throw ValidationException::withMessages(['code' => __('api.resend_wait')]);
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($previous && Hash::check($code, $previous->code_hash));
            EmailVerificationCode::updateOrCreate(['user_id' => $locked->id], ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(config('emtedad.auth.verification_minutes')), 'sent_at' => now()]);
            $locked->notify((new VerifyEmailCodeNotification($code))->locale($locked->preferred_locale));
        });
    }

    public function verify(User $user, string $code): User
    {
        $error = DB::transaction(function () use ($user, $code): ?string {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === UserStatusEnum::ACTIVE, 403, __('api.account_suspended'));
            if ($locked->hasVerifiedEmail()) {
                return null;
            }
            $record = $locked->verificationCode()->lockForUpdate()->first();
            if (! $record || $record->expires_at->isPast()) {
                return 'invalid_code';
            }
            if ($record->attempts >= config('emtedad.auth.verification_max_attempts')) {
                return 'code_attempts_exceeded';
            }
            if (! Hash::check($code, $record->code_hash)) {
                // Return the error so the failed attempt commits before validation is thrown.
                $record->increment('attempts');

                return 'invalid_code';
            }
            $locked->markEmailAsVerified();
            $record->delete();
            DB::afterCommit(fn() => event(new Verified($locked)));

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['code' => __('api.' . $error)]);
        }

        return $user->refresh()->load(User::ACCESS_RELATIONS);
    }
}
