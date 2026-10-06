<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Partner> */
class PartnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['website_url' => null, 'logo_path' => null, 'is_active' => false, 'sort_order' => 0];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }

    public function translated(): static
    {
        return $this->afterCreating(function (Partner $partner): void {
            foreach (config('emtedad.locales') as $locale) {
                $partner->translations()->create(['locale' => $locale, 'name' => fake()->company()]);
            }
        });
    }
}
