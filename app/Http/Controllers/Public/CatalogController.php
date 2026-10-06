<?php

namespace App\Http\Controllers\Public;

use App\Enum\MediaTypeEnum;
use App\Enum\MediaVisibilityEnum;
use App\Http\Controllers\ApiController;
use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Public\CategoryResource;
use App\Http\Resources\Public\HumanitarianCaseResource;
use App\Models\Category;
use App\Models\HumanitarianCase;
use App\Services\CaseFundingService;
use App\Services\CategoryService;
use App\Services\ContentFileService;
use App\Services\HumanitarianCaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends ApiController
{
    public function __construct(private readonly CategoryService $categories, private readonly HumanitarianCaseService $cases, private readonly ContentFileService $files) {}

    public function categories(CatalogIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->categories->index($request->validated(), true), CategoryResource::class));
    }

    public function category(int $category): JsonResponse
    {
        return $this->success(new CategoryResource(Category::visible()->with('translations')->findOrFail($category)));
    }

    public function categoryImage(int $category): StreamedResponse
    {
        $model = Category::visible()->findOrFail($category);
        abort_unless($model->image_path, 404);

        return $this->files->response($model->image_path);
    }

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->cases->index($request->validated(), true), HumanitarianCaseResource::class));
    }

    public function show(string $publicId): JsonResponse
    {
        return $this->detail($this->cases->publicCase($publicId));
    }

    public function bySlug(string $slug): JsonResponse
    {
        $case = HumanitarianCase::visible()->with(HumanitarianCase::CONTENT_RELATIONS)->whereHas('translations', fn ($q) => $q->where('locale', app()->getLocale())->where('slug', $slug))->firstOrFail();

        app(CaseFundingService::class)->load(new Collection([$case]));

        return $this->detail($case);
    }

    public function cover(string $publicId): StreamedResponse
    {
        $case = $this->cases->publicCase($publicId);
        abort_unless($case->cover_image_path, 404);

        return $this->files->response($case->cover_image_path);
    }

    public function media(string $publicId, int $media): StreamedResponse
    {
        $case = $this->cases->publicCase($publicId);
        $item = $case->media()->where('visibility', MediaVisibilityEnum::PUBLIC->value)->where('type', MediaTypeEnum::IMAGE->value)->findOrFail($media);

        return $this->files->response($item->path, diskName: $item->disk);
    }

    private function detail(HumanitarianCase $case): JsonResponse
    {
        $case->load(['media' => fn ($q) => $q->where('visibility', MediaVisibilityEnum::PUBLIC->value)->where('type', MediaTypeEnum::IMAGE->value)->orderBy('sort_order')->orderBy('id')]);

        return $this->success(new HumanitarianCaseResource($case));
    }
}
