<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

final class PartnersContentSeeder extends Seeder
{
    public function run(): void
    {
        SeedData::guard();
        foreach (SeedData::read('partners') as $row) {
            $names = array_column($row['translations'], 'name');
            $existing = Partner::query()->whereHas('translations', fn (Builder $query): Builder => $query->whereIn('name', $names))->first();
            if ($existing) {
                continue;
            }
            $model = Partner::create([
                'website_url' => $row['website_url'],
                'sort_order' => $row['sort_order'],
                'is_active' => false,
            ]);
            SeedData::translations($model, $row['translations']);
        }
    }
}
