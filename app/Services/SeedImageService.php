<?php

namespace App\Services;

use RuntimeException;

class SeedImageService
{
    /** @var array<string, string>|null */
    private ?array $sources = null;

    public function sourcePath(string $source): ?string
    {
        if (! preg_match('/\A[a-z0-9-]+\.png\z/', $source)) {
            return null;
        }
        $path = public_path('images/emtedad/'.$source);

        return is_file($path) ? $path : null;
    }

    public function path(string $path): ?string
    {
        if (! str_starts_with($path, 'seed/emtedad-v1/')) {
            return null;
        }
        $source = $this->sources()[$path] ?? null;

        return $source === null ? null : $this->sourcePath($source);
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $sources = [];
        $groups = [
            'categories' => ['directory' => 'categories', 'identifier' => 'seed_key'],
            'cases' => ['directory' => 'cases', 'identifier' => 'public_id'],
            'content-sections' => ['directory' => 'content', 'identifier' => 'key'],
        ];
        foreach ($groups as $name => $group) {
            $contents = file_get_contents(resource_path('seeders/emtedad/'.$name.'.json'));
            if ($contents === false) {
                throw new RuntimeException('Cannot read bundled image manifest: '.$name);
            }
            $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                if (! empty($row['asset'])) {
                    $path = 'seed/emtedad-v1/'.$group['directory'].'/'.$row[$group['identifier']].'.png';
                    $sources[$path] = $row['asset'];
                }
            }
        }

        return $this->sources = $sources;
    }
}
