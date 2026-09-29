<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Http\Resources\PaginatedResource;
use App\Models\Category;
use App\Services\CategoryService;
use App\Services\ContentFileService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryController extends ApiController
{
    public function __construct(private readonly CategoryService $service, private readonly ContentFileService $files) {}

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->service->index($request->validated()), CategoryResource::class));
    }

    public function store(SaveCategoryRequest $request): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->save(null, $request->validated())), __('content.created'), 201);
    }

    public function show(Category $category): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->show($category)));
    }

    public function update(SaveCategoryRequest $request, Category $category): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->save($category, $request->validated())));
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->service->delete($category);

        return $this->success(null, __('content.deleted'));
    }

    public function restore(int $id): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->restore($id)));
    }

    public function deleteTranslation(Category $category, string $locale): JsonResponse
    {
        $this->service->deleteTranslation($category, $locale);

        return $this->success();
    }

    public function uploadImage(UploadImageRequest $request, Category $category): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->image($category, $request->file('image'))));
    }

    public function deleteImage(Category $category): JsonResponse
    {
        return $this->success(new CategoryResource($this->service->image($category, null)));
    }

    public function image(Category $category): StreamedResponse
    {
        abort_unless($category->image_path, 404);

        return $this->files->response($category->image_path);
    }
}
