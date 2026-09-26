<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255'], 'token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'confirmed', 'max:128', Password::defaults()]];
    }
}
