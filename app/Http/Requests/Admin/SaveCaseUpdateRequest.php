<?php

namespace App\Http\Requests\Admin;

use App\Enum\PublicationStatusEnum;
use App\Http\Requests\ContentRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCaseUpdateRequest extends ContentRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(PublicationStatusEnum::class)],
            'published_at' => ['sometimes', 'nullable', 'date', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/'],
            'created_by' => ['prohibited'],
            'humanitarian_case_id' => ['prohibited'],
            'image_path' => ['prohibited'],
        ] + $this->translationRules('case_update_translations', 'case_update_id', $this->route('update')?->id, [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:30000'],
        ]);
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->requireDefaultLocale($v, ! $this->route('update'))];
    }
}
