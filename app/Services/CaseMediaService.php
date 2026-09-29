<?php

namespace App\Services;

use App\Enum\MediaTypeEnum;
use App\Enum\MediaVisibilityEnum;
use App\Enum\PermissionEnum;
use App\Models\CaseMedia;
use App\Models\HumanitarianCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CaseMediaService
{
    public function __construct(private readonly ContentFileService $files, private readonly HumanitarianCaseService $cases) {}

    public function index(HumanitarianCase $case, User $actor): Collection
    {
        return $case->media()->when(! $actor->can(PermissionEnum::CASES_DOCUMENTS_VIEW->value), fn($q) => $q->where('visibility', MediaVisibilityEnum::PUBLIC->value))->orderBy('sort_order')->orderBy('id')->get();
    }

    public function store(HumanitarianCase $case, array $data, UploadedFile $file, User $actor): CaseMedia
    {
        $path = null;
        try {
            return DB::transaction(function () use ($case, $data, $file, $actor, &$path) {
                $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                $visibility = MediaVisibilityEnum::from($data['visibility']);
                $this->authorizeWrite($locked, $actor, $visibility);
                if ($locked->media()->count() >= config('emtedad.content.max_media_per_case')) {
                    throw ValidationException::withMessages(['file' => __('content.media_limit')]);
                }
                $type = str_starts_with($file->getMimeType(), 'image/') ? MediaTypeEnum::IMAGE : MediaTypeEnum::DOCUMENT;
                if ($type === MediaTypeEnum::IMAGE && $file->getSize() > config('emtedad.content.image_max_kb') * 1024) {
                    throw ValidationException::withMessages(['file' => __('content.image_too_large')]);
                }
                $path = $this->files->store($file, 'cases/' . $locked->public_id . '/media');
                $media = $locked->media()->create([
                    'uploaded_by' => $actor->id,
                    'type' => $type,
                    'visibility' => $visibility,
                    'disk' => config('emtedad.content.disk'),
                    'path' => $path,
                    'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'sort_order' => $data['sort_order'] ?? 0,
                ]);
                $locked->forceFill(['updated_by' => $actor->id])->save();

                return $media;
            });
        } catch (Throwable $e) {
            $this->files->delete($path);
            throw $e;
        }
    }

    public function update(HumanitarianCase $case, int $mediaId, array $data, User $actor): CaseMedia
    {
        return DB::transaction(function () use ($case, $mediaId, $data, $actor) {
            $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $media = $locked->media()->whereKey($mediaId)->lockForUpdate()->firstOrFail();
            $next = isset($data['visibility']) ? MediaVisibilityEnum::from($data['visibility']) : $media->visibility;
            $this->authorizeWrite($locked, $actor, $media->visibility);
            if ($next !== $media->visibility) {
                $this->authorizeWrite($locked, $actor, $next);
            }
            if ($next === MediaVisibilityEnum::PUBLIC && $media->type !== MediaTypeEnum::IMAGE) {
                throw ValidationException::withMessages(['visibility' => __('content.public_images_only')]);
            }
            $media->fill(Arr::only($data, ['visibility', 'sort_order']))->save();
            $locked->forceFill(['updated_by' => $actor->id])->save();

            return $media;
        });
    }

    public function delete(HumanitarianCase $case, int $mediaId, User $actor): void
    {
        $path = DB::transaction(function () use ($case, $mediaId, $actor) {
            $locked = HumanitarianCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $media = $locked->media()->whereKey($mediaId)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($locked, $actor, $media->visibility);
            $path = $media->path;
            $media->delete();
            $locked->forceFill(['updated_by' => $actor->id])->save();

            return $path;
        });
        $this->files->delete($path);
    }

    public function download(HumanitarianCase $case, int $mediaId, User $actor): StreamedResponse
    {
        $media = $case->media()->whereKey($mediaId)->firstOrFail();
        if ($media->visibility === MediaVisibilityEnum::PRIVATE) {
            abort_unless($actor->can(PermissionEnum::CASES_DOCUMENTS_VIEW->value), 403, __('content.private_permission_required'));
        }

        // A server-generated filename avoids reflecting an untrusted upload name in headers.
        return $this->files->response($media->path, true, 'attachment-' . $media->id . '.' . pathinfo($media->path, PATHINFO_EXTENSION));
    }

    private function authorizeWrite(HumanitarianCase $case, User $actor, MediaVisibilityEnum $visibility): void
    {
        if ($visibility === MediaVisibilityEnum::PRIVATE) {
            abort_unless($actor->can(PermissionEnum::CASES_DOCUMENTS_MANAGE->value) && $actor->can(PermissionEnum::CASES_DOCUMENTS_VIEW->value), 403, __('content.private_permission_required'));
        } else {
            $this->cases->ensureEditable($case, $actor);
        }
    }
}
