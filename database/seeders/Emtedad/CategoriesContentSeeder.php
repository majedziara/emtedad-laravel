<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use RuntimeException;

final class CategoriesContentSeeder extends Seeder
{
    public function run(): void
    {
        SeedData::guard();
        foreach (SeedData::read('categories') as $row) {
            $slug = $row['translations'][1]['slug'];
            $query = Category::query();
            $query->withTrashed();
            $category = $query->whereHas('translations', fn (Builder $query): Builder => $query->where('locale', 'en')->where('slug', $slug))->first();
            if ($category && (bool) $category->getAttribute('deleted_at')) {
                throw new RuntimeException('An archived category owns slug '.$slug.'. Restore or resolve it in the dashboard first.');
            }
            if ($category && ! $category->is_active) {
                throw new RuntimeException('Existing category '.$slug.' is inactive. Resolve before seeding preview cases.');
            }
            if (! $category) {
                $category = Category::create([
                    'is_active' => $row['is_active'],
                    'sort_order' => $row['sort_order'],
                    'image_path' => SeedData::image($row['asset'], 'categories/'.$row['seed_key'].'.png'),
                ]);
                SeedData::translations($category, $row['translations']);
            }
            SeedData::$categories[$row['seed_key']] = (int) $category->getKey();
        }
    }
}
