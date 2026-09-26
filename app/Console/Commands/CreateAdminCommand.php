<?php

namespace App\Console\Commands;

use App\Enum\RoleEnum;
use App\Enum\UserStatusEnum;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateAdminCommand extends Command
{
    protected $signature = 'emtedad:create-admin {--email=} {--name=}';

    protected $description = 'Create a verified administrator interactively';

    public function handle(): int
    {
        if (! Role::where('name', RoleEnum::ADMIN->value)->where('guard_name', config('emtedad.permission_guard'))->exists()) {
            $this->error('Run: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }
        $data = ['name' => $this->option('name') ?: $this->ask('Name'), 'email' => Str::lower(trim($this->option('email') ?: $this->ask('Email'))), 'password' => $this->secret('Password'), 'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'confirmed', 'max:128', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'preferred_locale' => config('emtedad.default_locale')]);
            $user->forceFill(['email_verified_at' => now(), 'status' => UserStatusEnum::ACTIVE])->save();
            $user->assignRole(RoleEnum::ADMIN->value);
        });
        $this->info('Administrator created: ' . $data['email']);

        return self::SUCCESS;
    }
}
