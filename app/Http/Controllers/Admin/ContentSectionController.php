<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\SaveContentSectionRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\WebsiteIndexRequest;
use App\Http\Resources\Admin\ContentSectionResource;
use App\Http\Resources\PaginatedResource;
use App\Models\ContentSection;
use App\Services\ContentFileService;
use App\Services\WebsiteContentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentSectionController extends ApiController
{
    public function __construct(private readonly WebsiteContentService $service, private readonly ContentFileService $files) {}

    public function index(WebsiteIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = ContentSection::with('translations');
        if (isset($filters['website_page'])) {
            $query->where('page', $filters['website_page']);
        }

        return $this->success(new PaginatedResource($query->orderBy('sort_order')->orderBy('id')->paginate($filters['per_page'] ?? 20), ContentSectionResource::class));
    }

    public function store(SaveContentSectionRequest $request): JsonResponse
    {
        return $this->success(new ContentSectionResource($this->service->save(new ContentSection, $request->validated())), __('content.created'), 201);
    }

    public function show(ContentSection $contentSection): JsonResponse
    {
        return $this->success(new ContentSectionResource($contentSection->load('translations')));
    }

    public function update(SaveContentSectionRequest $request, ContentSection $contentSection): JsonResponse
    {
        return $this->success(new ContentSectionResource($this->service->save($contentSection, $request->validated())));
    }

    public function destroy(ContentSection $contentSection): JsonResponse
    {
        $this->service->delete($contentSection);

        return $this->success(null, __('content.deleted'));
    }

    public function deleteTranslation(ContentSection $contentSection, string $locale): JsonResponse
    {
        $this->service->deleteTranslation($contentSection, $locale);

        return $this->success();
    }

    public function uploadImage(UploadImageRequest $request, ContentSection $contentSection): JsonResponse
    {
        return $this->success(new ContentSectionResource($this->service->image($contentSection, $request->file('image'))));
    }

    public function deleteImage(ContentSection $contentSection): JsonResponse
    {
        return $this->success(new ContentSectionResource($this->service->image($contentSection, null)));
    }

    public function image(ContentSection $contentSection): StreamedResponse
    {
        abort_unless($contentSection->image_path, 404);

        return $this->files->response($contentSection->image_path);
    }
}
