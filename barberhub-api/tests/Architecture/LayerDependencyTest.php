<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LayerDependencyTest extends TestCase
{
    private const string SOURCE_PATH = __DIR__.'/../../src';

    /**
     * Namespaces each inner layer must never reference.
     *
     * @var array<string, list<string>>
     */
    private const array FORBIDDEN = [
        'Domain' => ['Illuminate\\', 'Laravel\\', '\\Application\\', '\\Infrastructure\\'],
        'Application' => ['Illuminate\\', 'Laravel\\', '\\Infrastructure\\'],
    ];

    /** Temporary source tree used to prove that violations are detected. */
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            $this->removeDirectory($this->fixtureRoot);
            $this->fixtureRoot = null;
        }

        parent::tearDown();
    }

    /** Inner layers stay free of framework and outer-layer references. */
    #[DataProvider('innerLayerFiles')]
    public function test_inner_layers_do_not_depend_on_outer_layers(string $layer, string $path): void
    {
        $this->assertSame([], $this->forbiddenReferences($layer, $path), "Forbidden dependency in {$path}");
    }

    /** The domain of a module only knows its own module and the shared kernels. */
    #[DataProvider('innerLayerFiles')]
    public function test_module_domains_do_not_depend_on_other_modules(string $layer, string $path): void
    {
        $foreign = $layer === 'Domain' ? $this->foreignModuleReferences($path) : [];

        $this->assertSame([], $foreign, "Dependency on another module in {$path}");
    }

    /** The scan must find files, otherwise the rules above would pass on nothing. */
    public function test_the_scan_finds_the_inner_layer_files(): void
    {
        $this->assertNotSame([], iterator_to_array(self::innerLayerFiles()));
    }

    /**
     * The rules report a forbidden reference written in a file under a Domain
     * or Application path, so the suite does fail when the layers are broken.
     *
     * @param  non-empty-string  $layer
     * @param  non-empty-string  $relativePath
     * @param  list<string>  $expected
     */
    #[DataProvider('violations')]
    public function test_the_rules_detect_violations(
        string $layer,
        string $relativePath,
        string $code,
        array $expected,
        bool $crossModule,
    ): void {
        $root = $this->makeFixtureRoot();
        $path = $this->writeFixture($root, $relativePath, $code);

        $discovered = iterator_to_array(self::filesUnder($root));
        $key = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertSame([$layer, $path], $discovered[$key] ?? null, 'The scan did not discover the file');

        $found = $crossModule
            ? $this->foreignModuleReferences($path, $root)
            : $this->forbiddenReferences($layer, $path);

        $this->assertSame($expected, $found);
    }

    /**
     * Forbidden references per layer, plus controls that must stay clean.
     *
     * @return iterable<string, array{string, string, string, list<string>, bool}>
     */
    public static function violations(): iterable
    {
        $domain = 'Catalog/Services/Domain/Probe.php';
        $application = 'Catalog/Services/Application/Probe.php';

        yield 'domain imports Illuminate' => ['Domain', $domain, self::code('use Illuminate\Support\Str;'), ['Illuminate\Support\Str'], false];
        yield 'domain imports Laravel' => ['Domain', $domain, self::code('use Laravel\Sanctum\HasApiTokens;'), ['Laravel\Sanctum\HasApiTokens'], false];
        yield 'domain writes Illuminate fully qualified' => ['Domain', $domain, self::code('', '\Illuminate\Support\Str::slug("x");'), ['Illuminate\Support\Str'], false];
        yield 'domain imports Application' => ['Domain', $domain, self::code('use Src\Catalog\Services\Application\Probe as Other;'), ['Src\Catalog\Services\Application\Probe'], false];
        yield 'domain imports Infrastructure' => ['Domain', $domain, self::code('use Src\Catalog\Services\Infrastructure\Probe;'), ['Src\Catalog\Services\Infrastructure\Probe'], false];
        yield 'application imports Illuminate' => ['Application', $application, self::code('use Illuminate\Support\Str;'), ['Illuminate\Support\Str'], false];
        yield 'application imports Laravel' => ['Application', $application, self::code('use Laravel\Sanctum\HasApiTokens;'), ['Laravel\Sanctum\HasApiTokens'], false];
        yield 'application imports Infrastructure' => ['Application', $application, self::code('use Src\Catalog\Services\Infrastructure\Probe;'), ['Src\Catalog\Services\Infrastructure\Probe'], false];
        yield 'domain imports another module' => ['Domain', $domain, self::code('use Src\Reservations\Reservations\Domain\Probe;'), ['Src\Reservations\Reservations\Domain\Probe'], true];
        yield 'domain imports another context shared module' => ['Domain', $domain, self::code('use Src\Identity\Shared\Domain\Probe;'), ['Src\Identity\Shared\Domain\Probe'], true];
        yield 'control: domain imports the shared kernel' => ['Domain', $domain, self::code('use Src\Shared\Domain\ValueObjects\Money;'), [], true];
        yield 'control: domain imports its own module' => ['Domain', $domain, self::code('use Src\Catalog\Services\Domain\Other;'), [], true];
        yield 'control: domain imports its context shared module' => ['Domain', $domain, self::code('use Src\Catalog\Shared\Domain\Other;'), [], true];
        yield 'control: application imports its own domain' => ['Application', $application, self::code('use Src\Catalog\Services\Domain\Other;'), [], false];
    }

    /**
     * Every PHP file that lives in a Domain or Application layer.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function innerLayerFiles(): iterable
    {
        return self::filesUnder(self::SOURCE_PATH);
    }

    /**
     * Every PHP file in a Domain or Application layer below the given root.
     *
     * @return iterable<string, array{string, string}>
     */
    private static function filesUnder(string $root): iterable
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $layer = $file instanceof SplFileInfo ? self::layerOf($file->getPathname()) : null;

            if ($layer !== null && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => [$layer, $file->getPathname()];
            }
        }
    }

    /** Resolve the inner layer a file belongs to, if any. */
    private static function layerOf(string $path): ?string
    {
        return array_find(
            array_keys(self::FORBIDDEN),
            fn (string $layer): bool => str_contains($path, DIRECTORY_SEPARATOR.$layer.DIRECTORY_SEPARATOR),
        );
    }

    /**
     * Imported or fully qualified names that break the dependency rule.
     *
     * @return list<string>
     */
    private function forbiddenReferences(string $layer, string $path): array
    {
        return array_values(array_filter(
            $this->referencesIn($path),
            fn (string $name): bool => array_any(
                self::FORBIDDEN[$layer],
                fn (string $needle): bool => str_contains("\\{$name}", $needle),
            ),
        ));
    }

    /**
     * Product classes referenced from outside the module and the shared kernels.
     *
     * @return list<string>
     */
    private function foreignModuleReferences(string $path, string $root = self::SOURCE_PATH): array
    {
        $own = explode(DIRECTORY_SEPARATOR, substr($path, strlen($root) + 1));
        $allowed = $own[0] === 'Shared'
            ? ['Src\\Shared\\']
            : ['Src\\Shared\\', "Src\\{$own[0]}\\Shared\\", "Src\\{$own[0]}\\{$own[1]}\\"];

        return array_values(array_filter(
            $this->referencesIn($path),
            fn (string $name): bool => str_starts_with($name, 'Src\\')
                && ! array_any($allowed, fn (string $prefix): bool => str_starts_with($name, $prefix)),
        ));
    }

    /**
     * Class names a file imports or writes fully qualified.
     *
     * @return list<string>
     */
    private function referencesIn(string $path): array
    {
        preg_match_all('/(?:^use\s+|\\\\)([A-Z][\w\\\\]+)/m', (string) file_get_contents($path), $matches);

        return $matches[1];
    }

    /** Minimal PHP source with an import and an optional statement. */
    private static function code(string $import, string $statement = ''): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\n{$import}\n\nfinal class Probe\n{\n    public function run(): void\n    {\n        {$statement}\n    }\n}\n";
    }

    private function makeFixtureRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'layer-dependency-'.bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return $this->fixtureRoot = $root;
    }

    private function writeFixture(string $root, string $relativePath, string $code): string
    {
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, $code);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
