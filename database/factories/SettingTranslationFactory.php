<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\SettingTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SettingTranslation> */
class SettingTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['setting_id' => Setting::factory(), 'locale' => config('emtedad.default_locale'), 'organization_name' => fake()->company(), 'address' => null, 'seo_title' => null, 'seo_description' => null];
    }
}
