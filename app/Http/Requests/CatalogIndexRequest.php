<?php

namespace App\Http\Requests;

use App\Enum\CasePriorityEnum;
use App\Enum\CaseStatusEnum;
use Illuminate\Validation\Rule;

class CatalogIndexRequest extends ApiRequest
{
    public function rules(): array
    {
        $admin = $this->is('api/v1/admin/*');

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'priority' => ['sometimes', Rule::enum(CasePriorityEnum::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'is_active' => $admin ? ['sometimes', 'boolean'] : ['prohibited'],
            'status' => $admin ? ['sometimes', Rule::enum(CaseStatusEnum::class)] : ['prohibited'],
            'trashed' => $admin ? ['sometimes', Rule::in(['without', 'only', 'with'])] : ['prohibited'],
            'sort' => ['sometimes', Rule::in(['latest', 'oldest', 'target_asc', 'target_desc'])],
        ];
    }
}
