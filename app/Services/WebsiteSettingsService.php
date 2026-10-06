<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
                'value' => config('website.defaults'),
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

            $values['donations_enabled'] = (bool) $values['donations_enabled'];

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
