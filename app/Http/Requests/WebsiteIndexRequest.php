<?php

namespace App\Http\Requests;

use App\Enum\PublicationStatusEnum;
use Illuminate\Validation\Rule;

class WebsiteIndexRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'website_page' => ['sometimes', Rule::in(['home', 'about', 'contact', 'footer', 'privacy', 'terms'])],
            'status' => $this->is('api/v1/admin/*') ? ['sometimes', Rule::enum(PublicationStatusEnum::class)] : ['prohibited'],
        ];
    }
}
