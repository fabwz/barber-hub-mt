<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

/**
 * Bytes of small but genuine images, built without any image extension. The validation reads the type with finfo and the size
 * with getimagesize, and neither needs the picture to be drawable. A weight above the natural one is added after the end of
 * the picture, where every reader ignores it, so a "3 MB PNG" is still a PNG.
 */
final class ImageFixtures
{
    /** A grayscale PNG of the given size, padded to at least the given number of bytes. */
    public static function png(int $width, int $height, int $atLeastBytes = 0): string
    {
        $rows = str_repeat("\0".str_repeat("\x80", $width), $height);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($rows, 9))
            .$chunk('IEND', '');

        return self::padded($png, $atLeastBytes);
    }

    /** A JPEG header of the given size (SOI, a frame and the end), padded to at least the given number of bytes. */
    public static function jpeg(int $width, int $height, int $atLeastBytes = 0): string
    {
        $frame = "\xFF\xC0".pack('n', 17).pack('CnnC', 8, $height, $width, 3)."\x01\x22\x00\x02\x11\x01\x03\x11\x01";

        return self::padded("\xFF\xD8".$frame."\xFF\xD9", $atLeastBytes);
    }

    /** An extended WebP header of the given size. */
    public static function webp(int $width, int $height): string
    {
        $size = static fn (int $value): string => substr(pack('V', $value - 1), 0, 3);
        $chunk = 'VP8X'.pack('V', 10)."\0\0\0\0".$size($width).$size($height);

        return 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;
    }

    /** A PDF document, the usual file that someone renames to .jpg. */
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** The start of a Windows executable. */
    public static function executable(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\0", 48).'This program cannot be run in DOS mode.';
    }

    /** The bytes followed by zeros until they reach the weight. */
    private static function padded(string $bytes, int $atLeastBytes): string
    {
        return strlen($bytes) >= $atLeastBytes ? $bytes : $bytes.str_repeat("\0", $atLeastBytes - strlen($bytes));
    }
}
