<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Shared\Domain\Exceptions\FileOutsideBarbershopException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Storage\FilesystemBarbershopFileStorage;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\TestCase;

final class FilesystemBarbershopFileStorageTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-0000-7000-8000-0000000000aa';

    private const string OTHER_ID = '0192f0c4-0000-7000-8000-0000000000bb';

    private string $source;

    /** Replace the media disk with a temporary one and prepare a file to copy. */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->source = (string) tempnam(sys_get_temp_dir(), 'bh');
        file_put_contents($this->source, 'contenido');
    }

    /** Remove the file prepared by the test. */
    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    /** The path starts at the folder of the barbershop of the context, with a UUID v7 name and the given extension. */
    public function test_path_is_derived_from_the_barbershop_of_the_context(): void
    {
        $path = $this->storage(self::BARBERSHOP_ID)->store('logo', $this->source, 'png');

        $this->assertMatchesRegularExpression('#^barbershops/'.self::BARBERSHOP_ID.'/logo/[0-9a-f-]{36}\.png$#', $path);
        $this->assertTrue(Str::isUuid(basename($path, '.png'), 7));
        $this->assertSame('contenido', Storage::disk('public')->get($path));
    }

    /** Two barbershops writing in the same directory never share a folder. */
    public function test_each_barbershop_writes_in_its_own_folder(): void
    {
        $first = $this->storage(self::BARBERSHOP_ID)->store('logo', $this->source, 'png');
        $second = $this->storage(self::OTHER_ID)->store('logo', $this->source, 'png');

        $this->assertStringStartsWith('barbershops/'.self::BARBERSHOP_ID.'/', $first);
        $this->assertStringStartsWith('barbershops/'.self::OTHER_ID.'/', $second);
    }

    /** Every upload gets its own name. */
    public function test_every_file_gets_its_own_name(): void
    {
        $storage = $this->storage(self::BARBERSHOP_ID);

        $this->assertNotSame(
            $storage->store('fotos/trabajos', $this->source, 'jpg'),
            $storage->store('fotos/trabajos', $this->source, 'jpg'),
        );
    }

    /** A barbershop deletes its own file and ignores a missing one. */
    public function test_deletes_its_own_file(): void
    {
        $storage = $this->storage(self::BARBERSHOP_ID);
        $path = $storage->store('logo', $this->source, 'png');

        $storage->delete($path);
        $storage->delete($path);

        Storage::disk('public')->assertMissing($path);
    }

    /** The address of an own file comes from the configured disk. */
    public function test_builds_the_address_of_its_own_file(): void
    {
        $path = $this->storage(self::BARBERSHOP_ID)->store('logo', $this->source, 'png');

        $this->assertSame('/storage/'.$path, $this->storage(self::BARBERSHOP_ID)->url($path));
    }

    /** A barbershop cannot delete the file of another: it fails as a missing file and the file stays. */
    public function test_cannot_delete_the_file_of_another_barbershop(): void
    {
        $path = $this->storage(self::OTHER_ID)->store('logo', $this->source, 'png');

        $this->assertRejected(fn () => $this->storage(self::BARBERSHOP_ID)->delete($path));
        Storage::disk('public')->assertExists($path);
    }

    /** A barbershop cannot get the address of the file of another. */
    public function test_cannot_get_the_address_of_the_file_of_another_barbershop(): void
    {
        $path = $this->storage(self::OTHER_ID)->store('logo', $this->source, 'png');

        $this->assertRejected(fn () => $this->storage(self::BARBERSHOP_ID)->url($path));
    }

    /** A path that leaves the folder of the barbershop is rejected on every operation, and the files outside stay. */
    #[DataProvider('escapingPaths')]
    public function test_rejects_paths_that_leave_the_folder(string $path): void
    {
        Storage::disk('public')->put('secreto.txt', 'no');
        $storage = $this->storage(self::BARBERSHOP_ID);

        $this->assertRejected(fn () => $storage->delete($path));
        $this->assertRejected(fn () => $storage->url($path));
        Storage::disk('public')->assertExists('secreto.txt');
    }

    /**
     * Paths that try to get out of the folder of barbershop A or to reach another one.
     *
     * @return array<string, array{string}>
     */
    public static function escapingPaths(): array
    {
        $own = 'barbershops/'.self::BARBERSHOP_ID.'/';

        return [
            'parent segment' => [$own.'../../secreto.txt'],
            'parent segment in the middle' => [$own.'logo/../../'.self::OTHER_ID.'/logo/a.png'],
            'current segment' => [$own.'./a.png'],
            'another barbershop' => ['barbershops/'.self::OTHER_ID.'/logo/a.png'],
            'prefix of the own folder' => ['barbershops/'.self::BARBERSHOP_ID.'x/logo/a.png'],
            'no folder at all' => ['secreto.txt'],
            'absolute path' => ['/'.$own.'logo/a.png'],
            'backslash' => [$own.'logo\\..\\..\\secreto.txt'],
            'null byte' => [$own."logo/a.png\0.txt"],
            'empty segment' => [$own.'logo//a.png'],
            'the folder itself' => [$own],
            'a url' => ['https://example.com/'.$own.'logo/a.png'],
        ];
    }

    /** A directory or extension that could leave the folder is rejected when storing, and nothing is written. */
    #[DataProvider('escapingDirectories')]
    public function test_rejects_directories_and_extensions_that_leave_the_folder(string $directory, string $extension): void
    {
        $this->assertRejected(fn () => $this->storage(self::BARBERSHOP_ID)->store($directory, $this->source, $extension));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * Directories and extensions that are not a plain name.
     *
     * @return array<string, array{string, string}>
     */
    public static function escapingDirectories(): array
    {
        return [
            'parent directory' => ['../otra', 'png'],
            'nested parent directory' => ['logo/../../otra', 'png'],
            'absolute directory' => ['/logo', 'png'],
            'empty directory' => ['', 'png'],
            'backslash directory' => ['logo\\..', 'png'],
            'extension with a slash' => ['logo', 'png/../x'],
            'extension with a dot' => ['logo', '.php'],
            'empty extension' => ['logo', ''],
        ];
    }

    /** The rejection is a not-found error, so it is answered like a missing resource (UP-06). */
    public function test_rejection_is_a_not_found_error(): void
    {
        $this->assertRejected(fn () => $this->storage(self::BARBERSHOP_ID)->url('barbershops/'.self::OTHER_ID.'/a.png'));
        $this->assertSame('shared.file_not_found', FileOutsideBarbershopException::create()->translationKey());
    }

    /** Storage of the files of a barbershop. */
    private function storage(string $barbershopId): FilesystemBarbershopFileStorage
    {
        return new FilesystemBarbershopFileStorage(new FixedBarbershopContext(new BarbershopId($barbershopId)));
    }

    /** Expect the operation to be rejected as a file outside the barbershop. */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A path outside the folder of the barbershop was accepted.');
        } catch (FileOutsideBarbershopException) {
            $this->addToAssertionCount(1);
        }
    }
}
