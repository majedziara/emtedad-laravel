<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:160'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32'], 'preferred_locale' => ['sometimes', Rule::in(config('emtedad.locales'))], 'email' => ['prohibited'], 'password' => ['prohibited'], 'role' => ['prohibited'], 'roles' => ['prohibited'], 'permissions' => ['prohibited'], 'status' => ['prohibited'], 'email_verified_at' => ['prohibited']];
    }
}
