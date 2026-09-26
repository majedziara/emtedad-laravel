<?php

namespace App\Models;

use App\Enum\UserStatusEnum;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ACCESS_RELATIONS = ['roles.permissions', 'permissions'];

    protected $fillable = ['name', 'email', 'phone', 'password', 'preferred_locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'status' => UserStatusEnum::class, 'last_login_at' => 'datetime'];
    }

    public function guardName(): string
    {
        return config('emtedad.permission_guard');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function createdCases(): HasMany
    {
        return $this->hasMany(HumanitarianCase::class, 'created_by');
    }

    public function verificationCode(): HasOne
    {
        return $this->hasOne(EmailVerificationCode::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetPasswordNotification($token))->locale($this->preferred_locale));
    }
}
