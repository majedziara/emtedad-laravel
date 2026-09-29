<?php

namespace App\Services;

use App\Enum\CaseStatusEnum;
use App\Enum\PermissionEnum;
use App\Models\Category;
use App\Models\HumanitarianCase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class HumanitarianCaseService
{
    public function __construct(private readonly ContentTranslationService $translations, private readonly ContentFileService $files) {}

    public function index(array $filters, bool $public = false): LengthAwarePaginator
    {
        $query = HumanitarianCase::with(HumanitarianCase::CONTENT_RELATIONS);
        if ($public) {
            $query->visible();
        } else {
            if (($filters['trashed'] ?? '') === 'only') {
                $query->onlyTrashed();
            }
            if (($filters['trashed'] ?? '') === 'with') {
                $query->withTrashed();
            }
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        }
        foreach (['category_id', 'priority', 'is_featured'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            $query->whereHas('translations', function ($q) use ($filters, $public) {
                if ($public) {
                    $q->whereIn('locale', [app()->getLocale(), config('emtedad.default_locale')]);
                }
                $q->where(fn($q) => $q->where('title', 'like', '%' . $filters['search'] . '%')->orWhere('summary', 'like', '%' . $filters['search'] . '%'));
            });
        }
        [$column, $direction] = match ($filters['sort'] ?? 'latest') {
            'oldest' => ['id', 'asc'],
            'target_asc' => ['target_amount_minor', 'asc'],
            'target_desc' => ['target_amount_minor', 'desc'],
            default => ['id', 'desc'],
        };
        $query->orderBy($column, $direction);
        if ($column !== 'id') {
            $query->orderBy('id');
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function show(HumanitarianCase $case): HumanitarianCase
    {
        return $case->load(HumanitarianCase::CONTENT_RELATIONS);
    }

    public function publicCase(string $publicId): HumanitarianCase
    {
        return HumanitarianCase::visible()->with(HumanitarianCase::CONTENT_RELATIONS)->where('public_id', $publicId)->firstOrFail();
    }

    public function ensureEditable(HumanitarianCase $case, User $actor): void
    {
        abort_if($case->trashed() || $case->status === CaseStatusEnum::ARCHIVED, 422, __('content.archived_case'));
        if ($case->status !== CaseStatusEnum::DRAFT) {
            abort_unless($actor->can(PermissionEnum::CASES_PUBLISH->value), 403, __('content.publisher_required'));
        }
    }

    public function save(?HumanitarianCase $case, array $data, User $actor): HumanitarianCase
    {
        return DB::transaction(function () use ($case, $data, $actor) {
            $model = $case ? HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail() : new HumanitarianCase;
            if ($model->exists) {
                $this->ensureEditable($model, $actor);
            }
            if (array_key_exists('beneficiary_reference', $data)) {
                abort_unless($actor->can(PermissionEnum::CASES_DOCUMENTS_MANAGE->value), 403, __('content.private_permission_required'));
            }
            if ($model->exists && $model->donations()->exists()) {
                if (isset($data['currency']) && $data['currency'] !== $model->currency->value || isset($data['target_amount_minor']) && (int) $data['target_amount_minor'] !== $model->target_amount_minor) {
                    throw ValidationException::withMessages(['currency' => __('content.financial_fields_locked')]);
                }
            }
            $model->fill(Arr::only($data, ['category_id', 'target_amount_minor', 'currency', 'beneficiaries_count', 'beneficiary_reference', 'country_code', 'priority', 'is_featured', 'starts_at', 'ends_at']));
            foreach (['starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $model->{$field} = $data[$field] ? Carbon::parse($data[$field])->utc() : null;
                }
            }
            if ($model->starts_at && $model->ends_at && $model->ends_at->lte($model->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => __('content.invalid_dates')]);
            }
            Category::whereKey($model->category_id)->lockForUpdate()->firstOrFail();
            if (! $model->exists) {
                $model->status = CaseStatusEnum::DRAFT;
                $model->created_by = $actor->id;
            }
            $model->updated_by = $actor->id;
            $model->save();
            if (isset($data['translations'])) {
                $this->translations->save($model, $data['translations']);
            }
            if ($model->status !== CaseStatusEnum::DRAFT) {
                $this->ensurePublishable($model, false);
            }

            return $this->show($model->refresh());
        });
    }

    public function status(HumanitarianCase $case, CaseStatusEnum $status, User $actor): HumanitarianCase
    {
        return DB::transaction(function () use ($case, $status, $actor) {
            $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->canTransitionTo($status)) {
                throw ValidationException::withMessages(['status' => __('content.invalid_transition')]);
            }
            if ($status === CaseStatusEnum::PUBLISHED) {
                $this->ensurePublishable($locked, true);
            }
            $locked->status = $status;
            $locked->updated_by = $actor->id;
            if ($status === CaseStatusEnum::DRAFT) {
                $locked->published_at = null;
            } else {
                $locked->published_at ??= now();
            }
            $locked->completed_at = $status === CaseStatusEnum::COMPLETED ? $locked->completed_at ?? now() : null;
            $locked->save();

            return $this->show($locked);
        });
    }

    public function archive(HumanitarianCase $case, User $actor): void
    {
        DB::transaction(function () use ($case, $actor) {
            $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => CaseStatusEnum::ARCHIVED, 'updated_by' => $actor->id])->save();
            $locked->delete();
        });
    }

    public function restore(int $id, User $actor): HumanitarianCase
    {
        return DB::transaction(function () use ($id, $actor) {
            $case = HumanitarianCase::onlyTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $case->forceFill([
                'status' => CaseStatusEnum::DRAFT,
                'published_at' => null,
                'completed_at' => null,
                'updated_by' => $actor->id,
            ]);
            $case->restore();

            return $this->show($case);
        });
    }

    public function deleteTranslation(HumanitarianCase $case, string $locale, User $actor): void
    {
        DB::transaction(function () use ($case, $locale, $actor) {
            $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->ensureEditable($locked, $actor);
            $this->translations->delete($locked, $locale);
            $locked->forceFill(['updated_by' => $actor->id])->save();
        });
    }

    public function cover(HumanitarianCase $case, ?UploadedFile $image, User $actor): HumanitarianCase
    {
        $new = null;
        $old = null;
        try {
            $result = DB::transaction(function () use ($case, $image, $actor, &$new, &$old) {
                $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                $this->ensureEditable($locked, $actor);
                if (! $image && $locked->status !== CaseStatusEnum::DRAFT) {
                    throw ValidationException::withMessages(['image' => __('content.cover_required')]);
                }
                $old = $locked->cover_image_path;
                $new = $image ? $this->files->store($image, 'cases/' . $locked->public_id . '/cover') : null;
                $locked->forceFill(['cover_image_path' => $new, 'updated_by' => $actor->id])->save();

                return $this->show($locked);
            });
        } catch (Throwable $e) {
            $this->files->delete($new);
            throw $e;
        }
        $this->files->delete($old);

        return $result;
    }

    private function ensurePublishable(HumanitarianCase $case, bool $checkDeadline): void
    {
        $category = Category::whereKey($case->category_id)->lockForUpdate()->first();
        if (! $category || ! $category->is_active) {
            throw ValidationException::withMessages(['category_id' => __('content.active_category_required')]);
        }
        if (! $case->translations()->where('locale', config('emtedad.default_locale'))->exists()) {
            throw ValidationException::withMessages(['translations' => __('content.default_locale_required')]);
        }
        if (! $case->cover_image_path || ! Storage::disk(config('emtedad.content.disk'))->exists($case->cover_image_path)) {
            throw ValidationException::withMessages(['image' => __('content.cover_required')]);
        }
        if ($checkDeadline && $case->ends_at && $case->ends_at->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => __('content.deadline_passed')]);
        }
    }
}
