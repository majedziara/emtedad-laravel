<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(2), 'group' => 'website', 'value' => config('website.defaults'), 'is_public' => true];
    }
}
