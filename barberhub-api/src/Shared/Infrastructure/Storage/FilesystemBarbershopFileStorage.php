<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\BarbershopFileStorage;
use Src\Shared\Domain\Exceptions\FileOutsideBarbershopException;

final readonly class FilesystemBarbershopFileStorage implements BarbershopFileStorage
{
    private const string DIRECTORY_SEGMENT = '/^[A-Za-z0-9_-]+$/';

    private const string FILE_SEGMENT = '/^[A-Za-z0-9_.-]+$/';

    private const string EXTENSION = '/^[a-z0-9]{1,10}$/';

    /** Receive the port that tells the barbershop whose folder every path must stay in. */
    public function __construct(private BarbershopContext $context) {}

    /** Copy the file to the media disk under the folder of the barbershop and a time-ordered UUID; the name sent by the browser is never used. */
    public function store(string $directory, string $sourcePath, string $extension): string
    {
        $segments = explode('/', $directory);

        foreach ($segments as $segment) {
            if (preg_match(self::DIRECTORY_SEGMENT, $segment) !== 1) {
                throw FileOutsideBarbershopException::create();
            }
        }

        if (preg_match(self::EXTENSION, $extension) !== 1) {
            throw FileOutsideBarbershopException::create();
        }

        $path = $this->folder().implode('/', $segments).'/'.Str::uuid7().'.'.$extension;

        $stream = fopen($sourcePath, 'rb');
        $this->disk()->put($path, $stream === false ? '' : $stream);

        return $path;
    }

    /** Remove the file from the media disk. */
    public function delete(string $path): void
    {
        $this->disk()->delete($this->own($path));
    }

    /** Ask the media disk for the public address of the file. */
    public function url(string $path): string
    {
        return Storage::disk($this->diskName())->url($this->own($path));
    }

    /** Folder of the current barbershop, with its trailing slash. */
    private function folder(): string
    {
        return 'barbershops/'.$this->context->current()->value.'/';
    }

    /** The path when it stays inside the folder of the barbershop; otherwise it is as if the file did not exist. */
    private function own(string $path): string
    {
        $folder = $this->folder();

        if (! str_starts_with($path, $folder)) {
            throw FileOutsideBarbershopException::create();
        }

        foreach (explode('/', substr($path, strlen($folder))) as $segment) {
            if (preg_match(self::FILE_SEGMENT, $segment) !== 1 || $segment === '.' || $segment === '..') {
                throw FileOutsideBarbershopException::create();
            }
        }

        return $path;
    }

    /** Disk configured for the files of the barbershops. */
    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /** Name of the media disk, read from the configuration. */
    private function diskName(): string
    {
        $name = config('filesystems.media');

        return is_string($name) ? $name : 'public';
    }
}
