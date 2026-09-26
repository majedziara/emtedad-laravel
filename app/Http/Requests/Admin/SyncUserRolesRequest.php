<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['roles' => ['required', 'array', 'min:1', 'max:10'], 'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', config('emtedad.permission_guard'))]];
    }
}
