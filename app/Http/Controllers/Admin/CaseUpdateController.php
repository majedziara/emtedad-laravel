<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\SaveCaseUpdateRequest;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\WebsiteIndexRequest;
use App\Http\Resources\Admin\CaseUpdateResource;
use App\Http\Resources\PaginatedResource;
use App\Models\CaseUpdate;
use App\Models\HumanitarianCase;
use App\Services\CaseUpdateService;
use App\Services\ContentFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseUpdateController extends ApiController
{
    public function __construct(private readonly CaseUpdateService $service, private readonly ContentFileService $files) {}

    public function index(WebsiteIndexRequest $request, HumanitarianCase $case): JsonResponse
    {
        $filters = $request->validated();
        $query = $case->updates()->with('translations');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $this->success(new PaginatedResource($query->orderByDesc('id')->paginate($filters['per_page'] ?? 20), CaseUpdateResource::class));
    }

    public function store(SaveCaseUpdateRequest $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new CaseUpdateResource($this->service->save($case, null, $request->validated(), $request->user())), __('content.created'), 201);
    }

    public function show(HumanitarianCase $case, CaseUpdate $update): JsonResponse
    {
        $this->belongs($case, $update);

        return $this->success(new CaseUpdateResource($update->load('translations')));
    }

    public function update(SaveCaseUpdateRequest $request, HumanitarianCase $case, CaseUpdate $update): JsonResponse
    {
        return $this->success(new CaseUpdateResource($this->service->save($case, $update, $request->validated(), $request->user())));
    }

    public function destroy(Request $request, HumanitarianCase $case, CaseUpdate $update): JsonResponse
    {
        $this->service->delete($case, $update, $request->user());

        return $this->success();
    }

    public function deleteTranslation(Request $request, HumanitarianCase $case, CaseUpdate $update, string $locale): JsonResponse
    {
        $this->service->deleteTranslation($case, $update, $locale, $request->user());

        return $this->success();
    }

    public function uploadImage(UploadImageRequest $request, HumanitarianCase $case, CaseUpdate $update): JsonResponse
    {
        return $this->success(new CaseUpdateResource($this->service->image($case, $update, $request->file('image'), $request->user())));
    }

    public function deleteImage(Request $request, HumanitarianCase $case, CaseUpdate $update): JsonResponse
    {
        return $this->success(new CaseUpdateResource($this->service->image($case, $update, null, $request->user())));
    }

    public function image(HumanitarianCase $case, CaseUpdate $update): StreamedResponse
    {
        $this->belongs($case, $update);
        abort_unless($update->image_path, 404);

        return $this->files->response($update->image_path);
    }

    private function belongs(HumanitarianCase $case, CaseUpdate $update): void
    {
        abort_unless($update->humanitarian_case_id === $case->id, 404);
    }
}
