<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Starts several PHP processes that wait behind the same gate, opens the gate once all of them are waiting, so their
 * requests leave at the same instant, and collects what each one printed. The gate is a PostgreSQL advisory lock held by
 * the test; a process that does not reach it, or does not finish, fails the test with a message instead of hanging it.
 */
final class Race
{
    public const int GATE = 54_127;

    private const int BOOT_TIMEOUT_SECONDS = 90;

    private const int FINISH_TIMEOUT_SECONDS = 90;

    /**
     * Run a script in one process per list of arguments; the script receives the gate as its first argument.
     *
     * @param  list<list<string>>  $arguments
     * @return list<string>
     */
    public static function run(string $script, array $arguments): array
    {
        $gate = DB::connection('pgsql_admin');
        $gate->select('select pg_advisory_lock(?)', [self::GATE]);
        $processes = [];

        try {
            foreach ($arguments as $args) {
                $processes[] = self::start($script, $args);
            }

            try {
                self::waitUntilAllWait($processes);
            } finally {
                $gate->select('select pg_advisory_unlock(?)', [self::GATE]);
            }

            self::waitUntilAllFinish($processes);

            return array_map(self::collect(...), $processes);
        } finally {
            foreach ($processes as $process) {
                if (proc_get_status($process['handle'])['running']) {
                    proc_terminate($process['handle']);
                }

                proc_close($process['handle']);
                @unlink($process['out']);
                @unlink($process['err']);
            }
        }
    }

    /**
     * Start one process; its output goes to files because the pipes of a process on Windows cannot be read without blocking.
     *
     * @param  list<string>  $args
     * @return array{handle: resource, out: string, err: string}
     */
    private static function start(string $script, array $args): array
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'race-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'race-err-');
        $handle = proc_open(
            [PHP_BINARY, $script, (string) self::GATE, ...$args],
            [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            base_path(),
            [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => 'barberhub_testing', 'DB_URL' => ''],
        );
        Assert::assertIsResource($handle, 'A process of the race could not be started.');

        return ['handle' => $handle, 'out' => $out, 'err' => $err];
    }

    /**
     * Poll PostgreSQL until every process is blocked on the gate; one that dies before reaching it fails the test.
     *
     * @param  list<array{handle: resource, out: string, err: string}>  $processes
     */
    private static function waitUntilAllWait(array $processes): void
    {
        $count = count($processes);
        $deadline = microtime(true) + self::BOOT_TIMEOUT_SECONDS;

        do {
            $waiting = (int) DB::connection('pgsql_admin')->scalar(
                "select count(*) from pg_locks where locktype = 'advisory' and objid = ? and not granted",
                [self::GATE],
            );

            if ($waiting >= $count) {
                return;
            }

            foreach ($processes as $process) {
                if (! proc_get_status($process['handle'])['running']) {
                    Assert::fail('A process of the race ended before reaching the gate: '.trim((string) file_get_contents($process['err'])).trim((string) file_get_contents($process['out'])));
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        Assert::fail("Only {$waiting} of {$count} processes reached the gate in ".self::BOOT_TIMEOUT_SECONDS.' seconds.');
    }

    /**
     * Wait until every process has ended.
     *
     * @param  list<array{handle: resource, out: string, err: string}>  $processes
     */
    private static function waitUntilAllFinish(array $processes): void
    {
        $deadline = microtime(true) + self::FINISH_TIMEOUT_SECONDS;

        do {
            $running = count(array_filter($processes, fn (array $process): bool => proc_get_status($process['handle'])['running']));

            if ($running === 0) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        Assert::fail("{$running} of ".count($processes).' processes did not finish in '.self::FINISH_TIMEOUT_SECONDS.' seconds after the gate opened.');
    }

    /**
     * What a finished process printed; anything on its error output fails the test.
     *
     * @param  array{handle: resource, out: string, err: string}  $process
     */
    private static function collect(array $process): string
    {
        $errors = trim((string) file_get_contents($process['err']));
        Assert::assertSame('', $errors, "A process of the race failed: {$errors}");

        return trim((string) file_get_contents($process['out']));
    }
}
