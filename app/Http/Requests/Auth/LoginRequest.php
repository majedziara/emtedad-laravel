<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class LoginRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:128'], 'remember_me' => ['sometimes', 'boolean'], 'device_name' => ['sometimes', 'string', 'max:100']];
    }
}
