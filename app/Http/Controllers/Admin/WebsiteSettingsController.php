<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\SaveWebsiteSettingsRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Resources\Admin\WebsiteSettingsResource;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\JsonResponse;

class WebsiteSettingsController extends ApiController
{
    public function __construct(private readonly WebsiteSettingsService $service) {}

    public function show(): JsonResponse
    {
        return $this->success(new WebsiteSettingsResource($this->service->get()));
    }

    public function update(SaveWebsiteSettingsRequest $request): JsonResponse
    {
        return $this->success(new WebsiteSettingsResource($this->service->save($request->validated())));
    }

    public function deleteTranslation(string $locale): JsonResponse
    {
        $this->service->deleteTranslation($locale);

        return $this->success();
    }

    public function uploadImage(UploadImageRequest $request, string $asset): JsonResponse
    {
        return $this->success(new WebsiteSettingsResource($this->service->image($asset, $request->file('image'))));
    }

    public function deleteImage(string $asset): JsonResponse
    {
        return $this->success(new WebsiteSettingsResource($this->service->image($asset, null)));
    }
}
