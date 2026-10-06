<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Services\WebsiteSettingsService;
use Illuminate\Database\Seeder;

final class WebsiteSettingsContentSeeder extends Seeder
{
    public function run(): void
    {
        SeedData::guard();
        $service = app(WebsiteSettingsService::class);
        $data = SeedData::read('settings');
        $setting = $service->record();
        $current = $service->values($setting);
        foreach ($data['settings'] as $key => $value) {
            if ($value === null && ! empty($current[$key])) {
                $data['settings'][$key] = $current[$key];
            }
        }
        $translations = $setting->translations()->get()->keyBy('locale');
        foreach ($data['translations'] as &$translation) {
            $address = $translations->get($translation['locale'])?->address;
            if (is_string($address) && trim($address) !== '') {
                $translation['address'] = $address;
            }
        }
        unset($translation);
        $service->save($data);
    }
}
