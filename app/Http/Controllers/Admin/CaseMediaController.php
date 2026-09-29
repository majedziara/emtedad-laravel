<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Admin\StoreCaseMediaRequest;
use App\Http\Requests\Admin\UpdateCaseMediaRequest;
use App\Http\Resources\Admin\CaseMediaResource;
use App\Models\HumanitarianCase;
use App\Services\CaseMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseMediaController extends ApiController
{
    public function __construct(private readonly CaseMediaService $service) {}

    public function index(Request $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(CaseMediaResource::collection($this->service->index($case, $request->user())));
    }

    public function store(StoreCaseMediaRequest $request, HumanitarianCase $case): JsonResponse
    {
        return $this->success(new CaseMediaResource($this->service->store($case, $request->validated(), $request->file('file'), $request->user())), __('content.created'), 201);
    }

    public function update(UpdateCaseMediaRequest $request, HumanitarianCase $case, int $media): JsonResponse
    {
        return $this->success(new CaseMediaResource($this->service->update($case, $media, $request->validated(), $request->user())));
    }

    public function destroy(Request $request, HumanitarianCase $case, int $media): JsonResponse
    {
        $this->service->delete($case, $media, $request->user());

        return $this->success(null, __('content.deleted'));
    }

    public function download(Request $request, HumanitarianCase $case, int $media): StreamedResponse
    {
        return $this->service->download($case, $media, $request->user());
    }
}
