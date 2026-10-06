<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Models\ContentSection;
use Illuminate\Database\Seeder;

final class WebsiteContentSeeder extends Seeder
{
    public function run(): void
    {
        SeedData::guard();
        foreach (SeedData::read('content-sections') as $row) {
            $section = ContentSection::firstOrNew(['key' => $row['key']]);
            $section->fill([
                'page' => $row['page'],
                'sort_order' => $row['sort_order'],
                'is_active' => $row['is_active'],
            ]);
            if ($row['asset'] && ! $section->image_path) {
                $section->image_path = SeedData::image($row['asset'], 'content/'.$row['key'].'.png');
            }
            $section->save();
            SeedData::translations($section, $row['translations']);
        }
    }
}
