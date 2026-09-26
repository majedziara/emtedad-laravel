<?php

namespace App\Http\Requests\Admin;

use App\Enum\PermissionEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:50', 'regex:/\A[a-z][a-z0-9_-]*\z/', Rule::unique('roles', 'name')->where('guard_name', config('emtedad.permission_guard'))->ignore($this->route('role')->id)], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_column(PermissionEnum::cases(), 'value'))]];
    }
}
