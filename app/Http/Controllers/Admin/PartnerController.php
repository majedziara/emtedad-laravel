<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\SavePartnerRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\WebsiteIndexRequest;
use App\Http\Resources\Admin\PartnerResource;
use App\Http\Resources\PaginatedResource;
use App\Models\Partner;
use App\Services\ContentFileService;
use App\Services\WebsiteContentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerController extends ApiController
{
    public function __construct(private readonly WebsiteContentService $service, private readonly ContentFileService $files) {}

    public function index(WebsiteIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Partner::with('translations');

        return $this->success(new PaginatedResource($query->orderBy('sort_order')->orderBy('id')->paginate($filters['per_page'] ?? 20), PartnerResource::class));
    }

    public function store(SavePartnerRequest $request): JsonResponse
    {
        return $this->success(new PartnerResource($this->service->save(new Partner, $request->validated())), __('content.created'), 201);
    }

    public function show(Partner $partner): JsonResponse
    {
        return $this->success(new PartnerResource($partner->load('translations')));
    }

    public function update(SavePartnerRequest $request, Partner $partner): JsonResponse
    {
        return $this->success(new PartnerResource($this->service->save($partner, $request->validated())));
    }

    public function destroy(Partner $partner): JsonResponse
    {
        $this->service->delete($partner);

        return $this->success(null, __('content.deleted'));
    }

    public function deleteTranslation(Partner $partner, string $locale): JsonResponse
    {
        $this->service->deleteTranslation($partner, $locale);

        return $this->success();
    }

    public function uploadImage(UploadImageRequest $request, Partner $partner): JsonResponse
    {
        return $this->success(new PartnerResource($this->service->image($partner, $request->file('image'))));
    }

    public function deleteImage(Partner $partner): JsonResponse
    {
        return $this->success(new PartnerResource($this->service->image($partner, null)));
    }

    public function logo(Partner $partner): StreamedResponse
    {
        abort_unless($partner->logo_path, 404);

        return $this->files->response($partner->logo_path);
    }
}
