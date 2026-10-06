<?php

namespace Database\Factories;

use App\Models\Partner;
use App\Models\PartnerTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PartnerTranslation> */
class PartnerTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['partner_id' => Partner::factory(), 'locale' => config('emtedad.default_locale'), 'name' => fake()->company(), 'description' => fake()->sentence()];
    }
}
