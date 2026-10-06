<?php

namespace App\Http\Resources\Public;

use App\Enum\CaseStatusEnum;
use App\Traits\SelectsContentTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HumanitarianCaseResource extends JsonResource
{
    use SelectsContentTranslation;

    public function toArray(Request $request): array
    {
        $translation = $this->contentTranslation();
        $detail = $request->routeIs('public.cases.show', 'public.cases.by-slug');

        return [
            'public_id' => $this->public_id,
            'locale' => $translation?->locale,
            'title' => $translation?->title,
            'slug' => $translation?->slug,
            'summary' => $translation?->summary,
            'story' => $this->when($detail, $translation?->story),
            'location' => $translation?->location,
            'meta_title' => $this->when($detail, $translation?->meta_title),
            'meta_description' => $this->when($detail, $translation?->meta_description),
            'localized_slugs' => $this->when($detail, fn () => $this->translations->pluck('slug', 'locale')),
            'category' => new CategoryResource($this->category),
            'target_amount_minor' => $this->target_amount_minor,
            'raised_amount_minor' => (int) $this->raised_amount_minor,
            'payment_environment' => config('paypal.mode'),
            'amount_basis' => 'confirmed_net_before_provider_fees',
            'currency' => $this->currency->value,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'is_featured' => $this->is_featured,
            'beneficiaries_count' => $this->beneficiaries_count,
            'country_code' => $this->country_code,
            'cover_url' => $this->cover_image_path ? route('public.cases.cover', ['publicId' => $this->public_id]) : null,
            'accepting_donations' => $this->status === CaseStatusEnum::PUBLISHED && (bool) $this->donations_enabled && (! $this->starts_at || $this->starts_at->lte(now())) && (! $this->ends_at || $this->ends_at->isFuture()) && in_array($this->currency->value, config('paypal.currencies'), true),
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type->value,
                'url' => route('public.cases.media', ['publicId' => $this->public_id, 'media' => $m->id]),
                'sort_order' => $m->sort_order,
            ])),
        ];
    }
}
