<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Helpers of the tests that upload images: a temporary disk for the files of the barbershops and real uploaded files, whose type
 * the server reads from their content (the fake files of the framework report it from their name, which would prove nothing).
 * Everything the test creates is removed when it ends.
 */
trait UploadsImages
{
    /** @var list<string> */
    private array $uploadedTemporaryFiles = [];

    /** Send the files of the barbershops to a temporary disk that is emptied when the test ends. */
    protected function fakeMediaDisk(): void
    {
        Storage::fake('public', ['url' => (string) config('filesystems.disks.public.url')]);

        $this->beforeApplicationDestroyed(function (): void {
            (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/public'));
        });
    }

    /** A file that arrives with the given name and bytes, as a browser would send it. */
    protected function uploadOf(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bhimg');
        file_put_contents($path, $bytes);
        $this->uploadedTemporaryFiles[] = $path;

        if (count($this->uploadedTemporaryFiles) === 1) {
            $this->beforeApplicationDestroyed(function (): void {
                foreach ($this->uploadedTemporaryFiles as $file) {
                    @unlink($file);
                }
            });
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Every file stored on the disk of the barbershops, as paths.
     *
     * @return list<string>
     */
    protected function storedFiles(): array
    {
        return array_values(Storage::disk('public')->allFiles());
    }
}
