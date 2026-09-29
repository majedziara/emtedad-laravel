<?php

namespace App\Http\Requests\Donations;

use App\Enum\DonationStatusEnum;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class DonationIndexRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::enum(DonationStatusEnum::class)],
            'case_public_id' => ['sometimes', 'uuid'],
        ];
    }
}
