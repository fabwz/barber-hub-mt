<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** The simulated providers and fakes live in `tests/` only: nothing that ships knows them, and nothing binds them outside the tests. */
final class TestDoublesStayInTestsTest extends TestCase
{
    private const array SHIPPED = ['app', 'bootstrap', 'config', 'database', 'routes', 'src'];

    /** No file that ships references the namespace of the tests. */
    public function test_shipped_code_never_references_the_tests(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->shippedFiles() as $file) {
            $scanned++;

            if (preg_match('/\bTests\\\\/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(100, $scanned, 'The scan must find the shipped files.');
        $this->assertSame([], $offenders);
    }

    /** No class that ships is a fake, a simulation or a script of a provider. */
    public function test_shipped_code_has_no_fake_or_simulated_provider(): void
    {
        $offenders = [];

        foreach ($this->shippedFiles() as $file) {
            if (preg_match('/^(Fake|Simulated|Scripted|InMemory)/', $file->getBasename('.php')) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    /** @return iterable<SplFileInfo> */
    private function shippedFiles(): iterable
    {
        foreach (self::SHIPPED as $directory) {
            $path = __DIR__.'/../../'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    yield $file;
                }
            }
        }
    }
}
