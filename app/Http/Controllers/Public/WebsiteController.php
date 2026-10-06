<?php

namespace App\Http\Controllers\Public;

use App\Enum\PublicationStatusEnum;
use App\Http\Controllers\ApiController;
use App\Http\Requests\WebsiteIndexRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Public\CaseUpdateResource;
use App\Http\Resources\Public\ContentSectionResource;
use App\Http\Resources\Public\HumanitarianCaseResource;
use App\Http\Resources\Public\PartnerResource;
use App\Http\Resources\Public\WebsiteSettingsResource;
use App\Models\ContentSection;
use App\Models\Partner;
use App\Services\ContentFileService;
use App\Services\HumanitarianCaseService;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteController extends ApiController
{
    public function __construct(private readonly WebsiteSettingsService $settings, private readonly HumanitarianCaseService $cases, private readonly ContentFileService $files) {}

    public function settings(): JsonResponse
    {
        return $this->success(new WebsiteSettingsResource($this->settings->get()));
    }

    public function home(): JsonResponse
    {
        return $this->success([
            'settings' => new WebsiteSettingsResource($this->settings->get()),
            'sections' => ContentSectionResource::collection($this->sections()->where('page', 'home')->limit(config('website.home.sections'))->get()),
            'partners' => PartnerResource::collection($this->activePartners()->limit(config('website.home.partners'))->get()),
            'featured_cases' => HumanitarianCaseResource::collection($this->cases->index(['is_featured' => true, 'per_page' => config('website.home.cases')], true)->getCollection()),
        ]);
    }

    public function content(WebsiteIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = $this->sections();
        if (isset($filters['website_page'])) {
            $query->where('page', $filters['website_page']);
        }

        return $this->success(new PaginatedResource($query->paginate($filters['per_page'] ?? 20), ContentSectionResource::class));
    }

    public function partners(WebsiteIndexRequest $request): JsonResponse
    {
        return $this->success(new PaginatedResource($this->activePartners()->paginate($request->validated('per_page', 20)), PartnerResource::class));
    }

    public function contentImage(int $contentSection): StreamedResponse
    {
        $model = $this->sections()->findOrFail($contentSection);
        abort_unless($model->image_path, 404);

        return $this->files->response($model->image_path);
    }

    public function partnerLogo(int $partner): StreamedResponse
    {
        $model = $this->activePartners()->findOrFail($partner);
        abort_unless($model->logo_path, 404);

        return $this->files->response($model->logo_path);
    }

    public function settingsImage(string $asset): StreamedResponse
    {
        $path = $this->settings->get()->value['_images'][$asset] ?? null;
        abort_unless($path, 404);

        return $this->files->response($path);
    }

    public function updates(WebsiteIndexRequest $request, string $publicId): JsonResponse
    {
        $case = $this->cases->publicCase($publicId);

        return $this->success(new PaginatedResource($case->updates()->where('status', PublicationStatusEnum::PUBLISHED->value)->whereNotNull('published_at')->where('published_at', '<=', now())->with(['translations', 'humanitarianCase'])->orderByDesc('published_at')->orderByDesc('id')->paginate($request->validated('per_page', 20)), CaseUpdateResource::class));
    }

    public function updateImage(string $publicId, int $update): StreamedResponse
    {
        $case = $this->cases->publicCase($publicId);
        $model = $case->updates()->where('status', PublicationStatusEnum::PUBLISHED->value)->whereNotNull('published_at')->where('published_at', '<=', now())->findOrFail($update);
        abort_unless($model->image_path, 404);

        return $this->files->response($model->image_path);
    }

    private function sections(): Builder
    {
        return ContentSection::where('is_active', true)->whereHas('translations', fn (Builder $q) => $q->where('locale', config('emtedad.default_locale')))->with('translations')->orderBy('sort_order')->orderBy('id');
    }

    private function activePartners(): Builder
    {
        return Partner::where('is_active', true)->whereHas('translations', fn (Builder $q) => $q->where('locale', config('emtedad.default_locale')))->with('translations')->orderBy('sort_order')->orderBy('id');
    }
}
