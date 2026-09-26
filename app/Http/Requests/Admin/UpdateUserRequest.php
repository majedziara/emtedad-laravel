<?php

namespace App\Http\Requests\Admin;

use App\Enum\UserStatusEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:160'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32'], 'preferred_locale' => ['sometimes', Rule::in(config('emtedad.locales'))], 'status' => ['sometimes', Rule::enum(UserStatusEnum::class)], 'email' => ['prohibited'], 'password' => ['prohibited'], 'roles' => ['prohibited'], 'permissions' => ['prohibited'], 'email_verified_at' => ['prohibited']];
    }
}
