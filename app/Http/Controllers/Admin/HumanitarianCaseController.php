<?php

namespace App\Http\Controllers\Admin;

use App\Enum\CaseStatusEnum;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\CaseStatusRequest;
use App\Http\Requests\Admin\SaveCaseRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\Admin\HumanitarianCaseResource;
use App\Http\Resources\PaginatedResource;
use App\Models\HumanitarianCase;
use App\Services\ContentFileService;
use App\Services\HumanitarianCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HumanitarianCaseController extends ApiController
{
    public function __construct(private readonly HumanitarianCaseService $service, private readonly ContentFileService $files) {}

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->service->index($request->validated()), HumanitarianCaseResource::class));
    }

    public function store(SaveCaseRequest $request): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->save(null, $request->validated(), $request->user())), __('content.created'), 201);
    }

    public function show(HumanitarianCase $case): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->show($case)));
    }

    public function update(SaveCaseRequest $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->save($case, $request->validated(), $request->user())));
    }

    public function status(CaseStatusRequest $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->status($case, CaseStatusEnum::from($request->validated('status')), $request->user())));
    }

    public function destroy(Request $request, HumanitarianCase $case): JsonResponse
    {
        $this->service->archive($case, $request->user());

        return $this->success(null, __('content.archived'));
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->restore($id, $request->user())));
    }

    public function deleteTranslation(Request $request, HumanitarianCase $case, string $locale): JsonResponse
    {
        $this->service->deleteTranslation($case, $locale, $request->user());

        return $this->success();
    }

    public function uploadCover(UploadImageRequest $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->cover($case, $request->file('image'), $request->user())));
    }

    public function deleteCover(Request $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new HumanitarianCaseResource($this->service->cover($case, null, $request->user())));
    }

    public function cover(HumanitarianCase $case): StreamedResponse
    {
        abort_unless($case->cover_image_path, 404);

        return $this->files->response($case->cover_image_path);
    }
}
