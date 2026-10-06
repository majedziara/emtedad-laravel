<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Enum\CasePriorityEnum;
use App\Enum\CaseStatusEnum;
use App\Enum\CurrencyEnum;
use App\Models\HumanitarianCase;
use Illuminate\Database\Seeder;
use RuntimeException;

final class HumanitarianCasesContentSeeder extends Seeder
{
    public function run(): void
    {
        SeedData::guard();
        foreach (SeedData::read('cases') as $row) {
            $query = HumanitarianCase::query();
            $query->withTrashed();
            if ($query->where('public_id', $row['public_id'])->exists()) {
                continue;
            }
            $categoryId = SeedData::$categories[$row['category_seed_key']] ?? null;
            if (! $categoryId) {
                throw new RuntimeException('Categories must be seeded before cases.');
            }
            $case = new HumanitarianCase;
            $case->public_id = $row['public_id'];
            $case->fill([
                'category_id' => $categoryId,
                'beneficiaries_count' => $row['beneficiaries_count'],
                'country_code' => $row['country_code'],
                'target_amount_minor' => $row['target_amount_minor'],
                'currency' => CurrencyEnum::from($row['currency']),
                'priority' => CasePriorityEnum::from($row['priority']),
                'status' => CaseStatusEnum::PUBLISHED,
                'is_featured' => $row['is_featured'],
                'cover_image_path' => SeedData::image($row['asset'], 'cases/'.$row['public_id'].'.png'),
                'published_at' => now()->subMinute(),
                'starts_at' => null,
                'ends_at' => null,
                'completed_at' => null,
                'created_by' => null,
                'updated_by' => null,
                'beneficiary_reference' => null,
            ]);
            $case->save();
            SeedData::translations($case, $row['translations']);
        }
    }
}
