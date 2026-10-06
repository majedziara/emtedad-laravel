<?php

namespace Database\Seeders;

use App\Services\WebsiteSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WebsiteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $setting = app(WebsiteSettingsService::class)->record();

            $organizationNames = [
                'ar' => 'جمعية امتداد الخيرية',
                'en' => 'Emtedad Charity Association',
            ];

            $supportedLocales = config('emtedad.locales', []);

            foreach ($organizationNames as $locale => $name) {
                if (! in_array($locale, $supportedLocales, true)) {
                    continue;
                }

                $setting->translations()->firstOrCreate(
                    [
                        'locale' => $locale,
                    ],
                    [
                        'organization_name' => $name,
                    ],
                );
            }
        });
    }
}
