<?php

declare(strict_types=1);

namespace Database\Seeders\Emtedad;

use App\Models\Category;
use App\Models\ContentSection;
use App\Models\HumanitarianCase;
use App\Models\Partner;
use App\Models\Setting;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SeedData
{
    public const VERSION = 'emtedad_preview_seed_v1';

    public static bool $running = false;

    /** @var array<string, int> */
    public static array $categories = [];

    public static function guard(): void
    {
        if (! self::$running) {
            throw new RuntimeException('Run these seeders through EmtedadPreviewSeeder only.');
        }
    }

    /** @return array<string, mixed>|list<array<string, mixed>> */
    public static function read(string $name): array
    {
        $path = resource_path('seeders/emtedad/'.$name.'.json');
        if (! is_file($path)) {
            throw new RuntimeException('Missing seed data: '.$path);
        }
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('Invalid seed data: '.$path);
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $translations */
    public static function translations(Model $model, array $translations): void
    {
        foreach ($translations as $translation) {
            $locale = $translation['locale'];
            unset($translation['locale']);
            $model->translations()->updateOrCreate(['locale' => $locale], $translation);
        }
    }

    public static function image(string $source, string $target): string
    {
        self::guard();
        $disk = Storage::disk(config('emtedad.content.disk'));
        $path = 'seed/emtedad-v1/'.$target;
        $bytes = file_get_contents(resource_path('seeders/emtedad/assets/'.$source));
        if ($bytes === false) {
            throw new RuntimeException('Cannot read image: '.$source);
        }
        if ($disk->exists($path)) {
            if (hash('sha256', $disk->get($path)) !== hash('sha256', $bytes)) {
                throw new RuntimeException('Existing image differs; refusing to overwrite: '.$path);
            }
        } elseif (! $disk->put($path, $bytes)) {
            throw new RuntimeException('Cannot write image: '.$path);
        }

        return $path;
    }

    public static function restoreMissingImages(): int
    {
        self::guard();
        $disk = Storage::disk(config('emtedad.content.disk'));
        $restored = 0;
        $groups = [
            ['data' => 'content-sections', 'model' => ContentSection::class, 'column' => 'image_path', 'directory' => 'content', 'identifier' => 'key'],
            ['data' => 'categories', 'model' => Category::class, 'column' => 'image_path', 'directory' => 'categories', 'identifier' => 'seed_key'],
            ['data' => 'cases', 'model' => HumanitarianCase::class, 'column' => 'cover_image_path', 'directory' => 'cases', 'identifier' => 'public_id'],
        ];
        foreach ($groups as $group) {
            foreach (self::read($group['data']) as $row) {
                if (empty($row['asset'])) {
                    continue;
                }
                $target = $group['directory'].'/'.$row[$group['identifier']].'.png';
                $path = 'seed/emtedad-v1/'.$target;
                if ($disk->exists($path) || ! $group['model']::query()->where($group['column'], $path)->exists()) {
                    continue;
                }
                self::image($row['asset'], $target);
                $restored++;
            }
        }

        return $restored;
    }

    public static function preflight(): void
    {
        foreach (['ar', 'en'] as $locale) {
            if (! in_array($locale, config('emtedad.locales', []), true)) {
                throw new RuntimeException('Enable ar and en in config/emtedad.php first.');
            }
        }
        $disk = config('emtedad.content.disk');
        if (! is_string($disk) || ! config('filesystems.disks.'.$disk)) {
            throw new RuntimeException('Configure emtedad.content.disk first.');
        }
        foreach (['record', 'values', 'save'] as $method) {
            if (! method_exists(WebsiteSettingsService::class, $method)) {
                throw new RuntimeException('WebsiteSettingsService is missing '.$method.'().');
            }
        }
        if (! is_string(config('website.settings_key'))) {
            throw new RuntimeException('Missing website.settings_key configuration.');
        }
        $requiredSettings = array_keys(self::read('settings')['settings']);
        if (array_diff($requiredSettings, array_keys(config('website.defaults', [])))) {
            throw new RuntimeException('website.defaults differs from the seed settings contract.');
        }
        $models = [
            Setting::class => [
                ['key', 'group', 'value', 'is_public'],
                ['locale', 'organization_name', 'address', 'seo_title', 'seo_description'],
            ],
            Partner::class => [
                ['website_url', 'sort_order', 'is_active'],
                ['locale', 'name', 'description'],
            ],
            ContentSection::class => [
                ['key', 'page', 'image_path', 'is_active', 'sort_order'],
                ['locale', 'title', 'subtitle', 'body', 'button_label', 'button_url', 'items'],
            ],
            Category::class => [
                ['image_path', 'is_active', 'sort_order'],
                ['locale', 'name', 'slug', 'description'],
            ],
            HumanitarianCase::class => [
                ['public_id', 'category_id', 'beneficiaries_count', 'country_code', 'target_amount_minor', 'currency', 'status', 'priority', 'is_featured', 'cover_image_path', 'published_at'],
                ['locale', 'title', 'slug', 'summary', 'story', 'location', 'meta_title', 'meta_description'],
            ],
        ];
        foreach ($models as $class => [$columns, $translationColumns]) {
            if (! class_exists($class)) {
                throw new RuntimeException('Current model is missing: '.$class);
            }
            $model = new $class;
            if (! Schema::hasColumns($model->getTable(), $columns)) {
                throw new RuntimeException('Schema mismatch in '.$model->getTable().'; check current migrations.');
            }
            if (! method_exists($model, 'translations')) {
                throw new RuntimeException($class.' is missing translations().');
            }
            $related = $model->translations()->getRelated();
            if (! Schema::hasColumns($related->getTable(), $translationColumns)) {
                throw new RuntimeException('Translation schema mismatch in '.$related->getTable().'.');
            }
        }
        foreach (['settings', 'partners', 'content-sections', 'categories', 'cases'] as $name) {
            $rows = self::read($name);
            foreach ($name === 'settings' ? [$rows] : $rows as $row) {
                if (array_column($row['translations'], 'locale') !== ['ar', 'en']) {
                    throw new RuntimeException('Expected ar/en translations in '.$name.'.json');
                }
                if (! empty($row['asset'])) {
                    $path = resource_path('seeders/emtedad/assets/'.$row['asset']);
                    if (! is_file($path) || filesize($path) === 0) {
                        throw new RuntimeException('Missing asset: '.$path);
                    }
                }
            }
        }
    }
}
