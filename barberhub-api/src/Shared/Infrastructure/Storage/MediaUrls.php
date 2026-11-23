<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Storage;

use Illuminate\Support\Facades\Storage;

final class MediaUrls
{
    /**
     * Public address of a stored file, or null when there is none. It is relative to the host (`/api/v1/archivos/...`), so it
     * works in production and behind the `/api` proxy of the web client. The path comes from the row of the barbershop that owns the file.
     */
    public static function of(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = config('filesystems.media');

        return Storage::disk(is_string($disk) ? $disk : 'public')->url($path);
    }
}
