<?php

namespace App\Services;

use App\Enum\PermissionEnum;
use App\Enum\PublicationStatusEnum;
use App\Models\CaseUpdate;
use App\Models\HumanitarianCase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class CaseUpdateService
{
    public function __construct(private readonly HumanitarianCaseService $cases, private readonly ContentTranslationService $translations, private readonly ContentFileService $files) {}

    public function save(HumanitarianCase $case, ?CaseUpdate $update, array $data, User $actor): CaseUpdate
    {
        return DB::transaction(function () use ($case, $update, $data, $actor): CaseUpdate {
            $parent = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->cases->ensureEditable($parent, $actor);
            $model = $update ? $parent->updates()->whereKey($update->id)->lockForUpdate()->firstOrFail() : new CaseUpdate(['humanitarian_case_id' => $parent->id, 'created_by' => $actor->id, 'status' => PublicationStatusEnum::DRAFT]);
            $next = isset($data['status']) ? PublicationStatusEnum::from($data['status']) : $model->status;
            if ($model->status === PublicationStatusEnum::PUBLISHED || $next === PublicationStatusEnum::PUBLISHED) {
                abort_unless($actor->can(PermissionEnum::CASES_PUBLISH->value), 403);
            }
            $model->status = $next;
            if (array_key_exists('published_at', $data)) {
                $model->published_at = $data['published_at'] ? Carbon::parse($data['published_at'])->utc() : null;
            }
            $model->published_at = $next === PublicationStatusEnum::PUBLISHED ? $model->published_at ?? now() : null;
            $model->save();
            if (isset($data['translations'])) {
                $this->translations->save($model, $data['translations']);
            }

            return $model->load('translations');
        });
    }

    public function delete(HumanitarianCase $case, CaseUpdate $update, User $actor): void
    {
        $path = DB::transaction(function () use ($case, $update, $actor): ?string {
            $locked = $this->editable($case, $update, $actor);
            $path = $locked->image_path;
            $locked->delete();

            return $path;
        });
        $this->files->delete($path);
    }

    public function deleteTranslation(HumanitarianCase $case, CaseUpdate $update, string $locale, User $actor): void
    {
        DB::transaction(fn () => $this->translations->delete($this->editable($case, $update, $actor), $locale));
    }

    public function image(HumanitarianCase $case, CaseUpdate $update, ?UploadedFile $image, User $actor): CaseUpdate
    {
        $new = null;
        $old = null;
        try {
            $result = DB::transaction(function () use ($case, $update, $image, $actor, &$new, &$old): CaseUpdate {
                $locked = $this->editable($case, $update, $actor);
                $old = $locked->image_path;
                $new = $image ? $this->files->store($image, 'cases/'.$case->public_id.'/updates/'.$locked->id) : null;
                $locked->image_path = $new;
                $locked->save();

                return $locked->load('translations');
            });
        } catch (Throwable $e) {
            $this->files->delete($new);
            throw $e;
        }
        $this->files->delete($old);

        return $result;
    }

    private function editable(HumanitarianCase $case, CaseUpdate $update, User $actor): CaseUpdate
    {
        $parent = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
        $this->cases->ensureEditable($parent, $actor);
        $locked = $parent->updates()->whereKey($update->id)->lockForUpdate()->firstOrFail();
        if ($locked->status === PublicationStatusEnum::PUBLISHED) {
            abort_unless($actor->can(PermissionEnum::CASES_PUBLISH->value), 403);
        }

        return $locked;
    }
}
