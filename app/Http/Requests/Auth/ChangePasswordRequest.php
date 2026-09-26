<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'confirmed', 'max:128', 'different:current_password', Password::defaults()]];
    }
}
