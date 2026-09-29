<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContentFileService
{
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, ['disk' => config('emtedad.content.disk')]);
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            if (! Storage::disk(config('emtedad.content.disk'))->delete($path)) {
                Log::warning('Content file cleanup failed.');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function response(string $path, bool $download = false, ?string $name = null): StreamedResponse
    {
        $disk = Storage::disk(config('emtedad.content.disk'));
        abort_unless($disk->exists($path), 404);
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        return $download ? $disk->download($path, $name, $headers) : $disk->response($path, $name, $headers);
    }
}
