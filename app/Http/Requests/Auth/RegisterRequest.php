<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')], 'password' => ['required', 'string', 'confirmed', 'max:128', Password::defaults()], 'phone' => ['nullable', 'string', 'max:32'], 'preferred_locale' => ['sometimes', Rule::in(config('emtedad.locales'))], 'device_name' => ['sometimes', 'string', 'max:100'], 'role' => ['prohibited'], 'roles' => ['prohibited'], 'permissions' => ['prohibited'], 'status' => ['prohibited'], 'email_verified_at' => ['prohibited']];
    }
}
