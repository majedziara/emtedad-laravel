<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class VerifyEmailRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }
}
