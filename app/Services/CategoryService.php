<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryService
{
    public function __construct(private readonly ContentTranslationService $translations, private readonly ContentFileService $files) {}

    public function index(array $filters, bool $public = false): LengthAwarePaginator
    {
        $query = Category::with('translations');
        if ($public) {
            $query->visible();
        } else {
            if (($filters['trashed'] ?? '') === 'only') {
                $query->onlyTrashed();
            }
            if (($filters['trashed'] ?? '') === 'with') {
                $query->withTrashed();
            }
            if (array_key_exists('is_active', $filters)) {
                $query->where('is_active', $filters['is_active']);
            }
        }
        if (! empty($filters['search'])) {
            $query->whereHas('translations', function ($q) use ($filters, $public) {
                if ($public) {
                    $q->whereIn('locale', [app()->getLocale(), config('emtedad.default_locale')]);
                }
                $q->where('name', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->orderBy('sort_order')->orderBy('id')->paginate($filters['per_page'] ?? 20);
    }

    public function show(Category $category): Category
    {
        return $category->load('translations');
    }

    public function save(?Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $model = $category ? Category::whereKey($category->id)->lockForUpdate()->firstOrFail() : new Category;
            $model->fill(Arr::only($data, ['is_active', 'sort_order']))->save();
            if (isset($data['translations'])) {
                $this->translations->save($model, $data['translations']);
            }

            return $this->show($model->refresh());
        });
    }

    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($locked->cases()->exists()) {
                throw ValidationException::withMessages(['category' => __('content.category_in_use')]);
            }
            $locked->delete();
        });
    }

    public function restore(int $id): Category
    {
        return DB::transaction(function () use ($id) {
            $category = Category::onlyTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $category->is_active = false;
            $category->restore();

            return $this->show($category);
        });
    }

    public function deleteTranslation(Category $category, string $locale): void
    {
        DB::transaction(function () use ($category, $locale) {
            $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->translations->delete($locked, $locale);
        });
    }

    public function image(Category $category, ?UploadedFile $image): Category
    {
        $new = null;
        $old = null;
        try {
            $result = DB::transaction(function () use ($category, $image, &$new, &$old) {
                $locked = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
                $old = $locked->image_path;
                $new = $image ? $this->files->store($image, 'categories/' . $locked->id) : null;
                $locked->image_path = $new;
                $locked->save();

                return $this->show($locked);
            });
        } catch (Throwable $e) {
            $this->files->delete($new);
            throw $e;
        }
        $this->files->delete($old);

        return $result;
    }
}
