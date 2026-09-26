<?php

namespace Database\Seeders;

use App\Enum\PermissionEnum;
use App\Enum\RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::transaction(function () {
            $guard = config('emtedad.permission_guard');
            foreach (PermissionEnum::cases() as $permission) {
                Permission::findOrCreate($permission->value, $guard);
            }
            Role::findOrCreate(RoleEnum::ADMIN->value, $guard)->syncPermissions(array_column(PermissionEnum::cases(), 'value'));
            Role::findOrCreate(RoleEnum::DONOR->value, $guard)->syncPermissions([]);
            $member = Role::firstOrCreate(['name' => RoleEnum::MEMBER->value, 'guard_name' => $guard]);
            if ($member->wasRecentlyCreated) {
                $member->syncPermissions([PermissionEnum::DASHBOARD_VIEW->value, PermissionEnum::CASES_VIEW->value, PermissionEnum::CASES_CREATE->value, PermissionEnum::CASES_UPDATE->value]);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
