<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContentFileService
{
    public function __construct(private readonly SeedImageService $seedImages) {}

    public function exists(string $path): bool
    {
        return $this->diskContaining($path) !== null || $this->seedImages->path($path) !== null;
    }

    public function store(UploadedFile $file, string $directory, ?string $diskName = null): string
    {
        return $file->store($directory, ['disk' => $diskName ?? config('emtedad.content.image_disk')]);
    }

    public function delete(?string $path, ?string $diskName = null): void
    {
        if (! $path) {
            return;
        }
        try {
            foreach ($this->diskNames($diskName) as $name) {
                $disk = Storage::disk($name);
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    Log::warning('Content file cleanup failed.');
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function response(string $path, bool $download = false, ?string $name = null, ?string $diskName = null): StreamedResponse
    {
        $disk = $this->diskContaining($path, $diskName);
        if ($disk === null) {
            $source = $this->seedImages->path($path);
            abort_unless($source !== null, 404);
            $disk = Storage::build(['driver' => 'local', 'root' => dirname($source), 'throw' => true]);
            $path = basename($source);
        }
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        return $download ? $disk->download($path, $name, $headers) : $disk->response($path, $name, $headers);
    }

    private function diskContaining(string $path, ?string $diskName = null): ?FilesystemAdapter
    {
        foreach ($this->diskNames($diskName) as $name) {
            $disk = Storage::disk($name);
            if ($disk->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function diskNames(?string $diskName): array
    {
        return $diskName === null ? array_values(array_unique([config('emtedad.content.image_disk'), config('emtedad.content.disk')])) : [$diskName];
    }
}
