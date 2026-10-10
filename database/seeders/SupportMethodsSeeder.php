<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $record = app(WebsiteSettingsService::class)->record();
        DB::transaction(function () use ($record): void {
            $setting = Setting::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $initial = [
                'gofundme_enabled' => true,
                'gofundme_url' => 'https://gofund.me/dfb837806',
                'bank_transfer_enabled' => true,
                'bank_name' => 'البنك الإسلامي الفلسطيني',
                'bank_beneficiary_name' => 'جمعية امتداد الخيرية',
                'bank_beneficiary_name_en' => null,
                'bank_swift' => null,
                'bank_iban_ils' => 'PS57PIBC084317933700033100000',
                'bank_iban_usd' => 'PS87PIBC084317933700013100000',
                'bank_iban_eur' => 'PS42PIBC084317933700043100000',
                'bank_iban_jod' => 'PS72PIBC084317933700023100000',
            ];
            $setting->value = array_replace($initial, $setting->value ?? []);
            $setting->save();
        });
    }
}
