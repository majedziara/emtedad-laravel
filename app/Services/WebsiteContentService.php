<?php

namespace App\Services;

use App\Models\ContentSection;
use App\Models\Partner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebsiteContentService
{
    public function __construct(private readonly ContentTranslationService $translations, private readonly ContentFileService $files) {}

    public function save(ContentSection|Partner $model, array $data): ContentSection|Partner
    {
        return DB::transaction(function () use ($model, $data): ContentSection|Partner {
            $locked = $model->exists ? $model->newQuery()->whereKey($model->id)->lockForUpdate()->firstOrFail() : $model;
            $fields = $locked instanceof ContentSection ? ['page', 'is_active', 'sort_order'] : ['website_url', 'is_active', 'sort_order'];
            if (! $locked->exists && $locked instanceof ContentSection) {
                $fields[] = 'key';
            }
            $locked->fill(Arr::only($data, $fields))->save();
            if (isset($data['translations'])) {
                $this->translations->save($locked, $data['translations']);
            }

            return $locked->refresh()->load('translations');
        });
    }

    public function delete(ContentSection|Partner $model): void
    {
        $path = $model instanceof ContentSection ? $model->image_path : $model->logo_path;
        DB::transaction(fn () => $model->delete());
        $this->files->delete($path);
    }

    public function deleteTranslation(ContentSection|Partner $model, string $locale): void
    {
        DB::transaction(function () use ($model, $locale): void {
            $locked = $model->newQuery()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $this->translations->delete($locked, $locale);
        });
    }

    public function image(ContentSection|Partner $model, ?UploadedFile $image): ContentSection|Partner
    {
        $field = $model instanceof ContentSection ? 'image_path' : 'logo_path';
        $new = null;
        $old = null;
        try {
            $result = DB::transaction(function () use ($model, $field, $image, &$new, &$old): ContentSection|Partner {
                $locked = $model->newQuery()->whereKey($model->id)->lockForUpdate()->firstOrFail();
                $old = $locked->{$field};
                $new = $image ? $this->files->store($image, $locked->getTable().'/'.$locked->id) : null;
                $locked->{$field} = $new;
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
}
