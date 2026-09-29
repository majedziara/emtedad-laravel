<?php

namespace App\Http\Requests\Admin;

use App\Enum\CaseStatusEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class CaseStatusRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(CaseStatusEnum::class)->except([CaseStatusEnum::ARCHIVED])]];
    }
}
