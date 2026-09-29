<?php

namespace App\Http\Requests\Admin;

use App\Enum\CasePriorityEnum;
use App\Enum\CurrencyEnum;
use App\Http\Requests\ContentRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCaseRequest extends ContentRequest
{
    public function rules(): array
    {
        $required = $this->route('case') ? 'sometimes' : 'required';
        $dateRules = ['sometimes', 'nullable', 'date', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/'];

        return [
            'category_id' => [$required, 'required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'target_amount_minor' => [$required, 'required', 'integer', 'min:1', 'max:' . config('emtedad.content.max_amount_minor')],
            'currency' => [$required, 'required', Rule::enum(CurrencyEnum::class)],
            'beneficiaries_count' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'beneficiary_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'priority' => ['sometimes', Rule::enum(CasePriorityEnum::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'public_id' => ['prohibited'],
            'cover_image_path' => ['prohibited'],
            'published_at' => ['prohibited'],
            'completed_at' => ['prohibited'],
        ] + $this->translationRules('case_translations', 'humanitarian_case_id', $this->route('case')?->id, [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'story' => ['required', 'string', 'max:50000'],
            'location' => ['nullable', 'string', 'max:180'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ]);
    }

    public function after(): array
    {
        return [fn(Validator $v) => $this->requireDefaultLocale($v, ! $this->route('case'))];
    }
}
