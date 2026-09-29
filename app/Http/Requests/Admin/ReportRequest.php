<?php

namespace App\Http\Requests\Admin;

use App\Enum\CurrencyEnum;
use App\Enum\ReportIntervalEnum;
use App\Http\Requests\ApiRequest;
use App\Services\DonationReportService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $defaults = [
            'date_from' => $today->subDays(config('reports.default_days') - 1)->toDateString(),
            'date_to' => $today->toDateString(),
            'environment' => config('paypal.mode'),
            'interval' => ReportIntervalEnum::DAY->value,
        ];
        $this->merge(array_replace($defaults, $this->all()));
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'currency' => ['sometimes', 'required', Rule::enum(CurrencyEnum::class)],
            'case_public_id' => ['sometimes', 'required', 'uuid'],
            'category_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', Rule::in(DonationReportService::CAPTURED_STATES)],
            'donor_type' => ['sometimes', 'required', Rule::in(['registered', 'guest'])],
            'interval' => ['required', Rule::enum(ReportIntervalEnum::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . config('reports.max_per_page')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('date_from') || $validator->errors()->has('date_to')) {
                return;
            }
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'), 'UTC');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'), 'UTC');
            if ($from->diffInDays($to) + 1 > config('reports.max_days')) {
                $validator->errors()->add('date_to', __('reports.period_too_long', ['days' => config('reports.max_days')]));
            }
        }];
    }
}
