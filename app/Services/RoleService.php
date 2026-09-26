<?php

namespace App\Services;

use App\Enum\RoleEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function index(): Collection
    {
        return Role::with('permissions')->where('guard_name', config('emtedad.permission_guard'))->orderBy('name')->get();
    }

    public function permissions(): Collection
    {
        return Permission::where('guard_name', config('emtedad.permission_guard'))->orderBy('name')->get(['id', 'name']);
    }

    public function store(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => config('emtedad.permission_guard')]);
            $role->syncPermissions($data['permissions']);

            return $role->load('permissions');
        });
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $locked = Role::where('guard_name', config('emtedad.permission_guard'))->whereKey($role->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->name, [RoleEnum::ADMIN->value, RoleEnum::DONOR->value], true)) {
                throw ValidationException::withMessages(['role' => __('api.protected_role')]);
            }
            if ($locked->name === RoleEnum::MEMBER->value && isset($data['name']) && $data['name'] !== $locked->name) {
                throw ValidationException::withMessages(['name' => __('api.protected_role')]);
            }
            if (isset($data['name'])) {
                $locked->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $locked->syncPermissions($data['permissions']);
            }

            return $locked->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role) {
            $locked = Role::where('guard_name', config('emtedad.permission_guard'))->whereKey($role->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->name, array_column(RoleEnum::cases(), 'value'), true)) {
                throw ValidationException::withMessages(['role' => __('api.protected_role')]);
            }
            if ($locked->users()->exists()) {
                throw ValidationException::withMessages(['role' => __('api.role_in_use')]);
            }
            $locked->delete();
        });
    }
}
