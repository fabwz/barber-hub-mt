<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Requests;

use Illuminate\Http\UploadedFile;

final class ImageUpload
{
    /**
     * Image types read from the content of the file, with the extension the server gives them. The name the browser sends is never trusted (SV-06).
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Largest file, in kilobytes: 2 MB (SV-06). */
    public const int MAX_KILOBYTES = 2048;

    /**
     * Rules for an uploaded image: real image content, weight and, when given, a minimum size in pixels.
     *
     * @return list<string>
     */
    public static function rules(?int $minWidth = null, ?int $minHeight = null): array
    {
        $rules = [
            'required',
            'file',
            'mimetypes:'.implode(',', array_keys(self::TYPES)),
            'max:'.self::MAX_KILOBYTES,
        ];

        if ($minWidth !== null && $minHeight !== null) {
            $rules[] = "dimensions:min_width={$minWidth},min_height={$minHeight}";
        }

        return $rules;
    }

    /** Extension that matches the content of a validated image, never the one in its name. */
    public static function extensionOf(UploadedFile $image): string
    {
        return self::TYPES[(string) $image->getMimeType()];
    }
}
