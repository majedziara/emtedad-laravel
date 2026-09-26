<?php

namespace App\Services;

use App\Enum\RoleEnum;
use App\Enum\UserStatusEnum;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function index(array $filters): LengthAwarePaginator
    {
        return User::with(User::ACCESS_RELATIONS)
            ->when($filters['search'] ?? null, fn($q, $search) => $q->where(fn($q) => $q->where('name', 'like', '%' . $search . '%')->orWhere('email', 'like', '%' . $search . '%')))
            ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn($q, $role) => $q->role($role))
            ->latest('id')->paginate($filters['per_page'] ?? 20);
    }

    public function show(User $user): User
    {
        return $user->loadMissing(User::ACCESS_RELATIONS);
    }

    public function store(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create(Arr::only($data, ['name', 'email', 'phone', 'password']) + ['preferred_locale' => $data['preferred_locale'] ?? config('emtedad.default_locale')]);
            $user->syncRoles($data['roles']);
            $this->verification->send($user);

            return $user->refresh()->load(User::ACCESS_RELATIONS);
        });
    }

    public function update(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data) {
            $this->lockAdmins($actor);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (isset($data['status']) && $data['status'] !== UserStatusEnum::ACTIVE->value) {
                if ($actor->id === $locked->id) {
                    throw ValidationException::withMessages(['status' => __('api.cannot_suspend_self')]);
                }
                $this->ensureAnotherAdminExists($locked);
                $locked->tokens()->delete();
            }
            $locked->forceFill(Arr::only($data, ['name', 'phone', 'preferred_locale', 'status']))->save();

            return $locked->refresh()->load(User::ACCESS_RELATIONS);
        });
    }

    public function syncRoles(User $actor, User $user, array $roles): User
    {
        return DB::transaction(function () use ($actor, $user, $roles) {
            $this->lockAdmins($actor);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! in_array(RoleEnum::ADMIN->value, $roles, true)) {
                if ($actor->id === $locked->id) {
                    throw ValidationException::withMessages(['roles' => __('api.cannot_remove_own_admin')]);
                }
                $this->ensureAnotherAdminExists($locked);
            }
            $locked->syncRoles($roles);

            return $locked->refresh()->load(User::ACCESS_RELATIONS);
        });
    }

    private function lockAdmins(User $actor): void
    {
        // Serialize changes that could remove the last usable administrator.
        User::role(RoleEnum::ADMIN->value)->where('status', UserStatusEnum::ACTIVE->value)->whereNotNull('email_verified_at')->orderBy('id')->lockForUpdate()->get();
        $fresh = $actor->fresh();
        abort_unless($fresh && $fresh->status === UserStatusEnum::ACTIVE && $fresh->hasVerifiedEmail() && $fresh->hasRole(RoleEnum::ADMIN->value), 403, __('api.forbidden'));
    }

    private function ensureAnotherAdminExists(User $user): void
    {
        if (! $user->hasRole(RoleEnum::ADMIN->value) || $user->status !== UserStatusEnum::ACTIVE || ! $user->hasVerifiedEmail()) {
            return;
        }
        $exists = User::role(RoleEnum::ADMIN->value)->whereKeyNot($user->id)->where('status', UserStatusEnum::ACTIVE->value)->whereNotNull('email_verified_at')->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['roles' => __('api.last_admin')]);
        }
    }
}
