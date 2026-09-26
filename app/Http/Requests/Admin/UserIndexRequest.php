<?php

namespace App\Http\Requests\Admin;

use App\Enum\UserStatusEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UserIndexRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::enum(UserStatusEnum::class)], 'role' => ['sometimes', Rule::exists('roles', 'name')->where('guard_name', config('emtedad.permission_guard'))], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
