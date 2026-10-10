<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebsiteSettingsService
{
    public function __construct(
        private readonly ContentTranslationService $translations,
        private readonly ContentFileService $files,
    ) {}

    public function get(): Setting
    {
        $setting = Setting::with('translations')
            ->where('key', config('website.settings_key'))
            ->first();

        return $setting ?? (new Setting([
            'value' => config('website.defaults'),
        ]))->setRelation('translations', collect());
    }

    public function values(Setting $setting): array
    {
        $defaults = config('website.defaults');

        return array_replace(
            $defaults,
            Arr::only(
                $setting->value ?? [],
                array_keys($defaults),
            ),
        );
    }

    public function acceptsDonations(): bool
    {
        return (bool) $this->values($this->get())['donations_enabled'];
    }

    public function record(): Setting
    {
        return Setting::firstOrCreate(
            [
                'key' => config('website.settings_key'),
            ],
            [
                'group' => 'website',
                'is_public' => true,
                'value' => [],
            ],
        );
    }

    public function save(array $data): Setting
    {
        $setting = $this->record();

        return DB::transaction(function () use ($setting, $data): Setting {
            $locked = Setting::whereKey($setting->id)
                ->lockForUpdate()
                ->firstOrFail();

            $values = array_replace(
                $this->values($locked),
                Arr::only(
                    $data['settings'] ?? [],
                    array_keys(config('website.defaults')),
                ),
            );

            foreach (['donations_enabled', 'gofundme_enabled', 'bank_transfer_enabled'] as $key) {
                $values[$key] = (bool) $values[$key];
            }
            $this->validateSupport($values);

            $locked->value = array_replace($locked->value ?? [], $values);
            $locked->save();

            if (isset($data['translations'])) {
                $this->translations->save(
                    $locked,
                    $data['translations'],
                );
            }

            return $locked->load('translations');
        });
    }

    public function deleteTranslation(string $locale): void
    {
        DB::transaction(function () use ($locale): void {
            $setting = Setting::where(
                'key',
                config('website.settings_key'),
            )
                ->lockForUpdate()
                ->firstOrFail();

            $this->translations->delete($setting, $locale);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function validateSupport(array $values): void
    {
        $errors = [];
        if ($values['gofundme_enabled'] && ! filled($values['gofundme_url'])) {
            $errors['settings.gofundme_url'] = __('support.gofundme_required');
        }
        if ($values['bank_transfer_enabled']) {
            foreach (['bank_name', 'bank_beneficiary_name'] as $key) {
                if (! filled($values[$key])) {
                    $errors['settings.'.$key] = __('support.bank_identity_required');
                }
            }
            $hasAccount = collect(config('website.bank_currencies'))
                ->contains(fn (string $currency): bool => filled($values['bank_iban_'.strtolower($currency)]));
            if (! $hasAccount) {
                $errors['settings.bank_iban_ils'] = __('support.account_required');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function image(string $asset, ?UploadedFile $image): Setting
    {
        abort_unless(in_array($asset, ['logo', 'favicon'], true), 404);
        $setting = $this->record();
        $new = null;
        $old = null;
        try {
            $result = DB::transaction(function () use ($setting, $asset, $image, &$new, &$old): Setting {
                $locked = Setting::whereKey($setting->id)->lockForUpdate()->firstOrFail();
                $values = $locked->value ?? [];
                $old = $values['_images'][$asset] ?? null;
                $new = $image ? $this->files->store($image, 'website/'.$asset) : null;
                $values['_images'][$asset] = $new;
                $locked->value = $values;
                $locked->save();

                return $locked->load('translations');
            });
        } catch (Throwable $e) {
            $this->files->delete($new);
            throw $e;
        }
        $this->files->delete($old);

        return $result;
    }
}
