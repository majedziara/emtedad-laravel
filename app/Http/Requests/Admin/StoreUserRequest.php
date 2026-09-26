<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')], 'password' => ['required', 'string', 'confirmed', 'max:128', Password::defaults()], 'phone' => ['nullable', 'string', 'max:32'], 'preferred_locale' => ['sometimes', Rule::in(config('emtedad.locales'))], 'roles' => ['required', 'array', 'min:1', 'max:10'], 'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', config('emtedad.permission_guard'))], 'permissions' => ['prohibited'], 'status' => ['prohibited'], 'email_verified_at' => ['prohibited']];
    }
}
