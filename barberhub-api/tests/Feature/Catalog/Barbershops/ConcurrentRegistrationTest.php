<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class ConcurrentRegistrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const int WAIT_SECONDS = 20;

    /**
     * A registration that loses the race for a name gets the next number instead of failing (RF-02, MT-07).
     *
     * One session registers "pura-navaja" and holds its transaction open. A second process, with its own
     * connection, registers the same name and has to wait for the first one; once the first commits, the unique
     * key refuses the second's link and it retries as "pura-navaja-2".
     */
    public function test_simultaneous_registrations_of_the_same_name_get_distinct_links(): void
    {
        $admin = DB::connection('pgsql_admin');
        $ownerId = (string) Str::uuid7();
        $process = null;
        $result = null;

        try {
            $admin->table('users')->insert(['id' => $ownerId, 'name' => 'Ana Mora', 'email' => 'concurrencia@barberhub.test', 'password' => 'hash']);

            $admin->beginTransaction();
            $admin->table('barbershops')->insert(['name' => 'Barbería Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'pura-navaja']);

            $process = $this->startWorker('Barbería Pura Navaja', $ownerId);

            $this->waitUntilTheWorkerWaitsForTheLock($admin, $process['handle']);
            $admin->commit();

            $result = $this->finishWorker($process);
            $links = $admin->table('barbershops')->orderBy('public_link')->pluck('public_link')->all();
            $owners = $admin->table('memberships')->where('barbershop_id', $result['id'])->where('user_id', $ownerId)->where('role', 'owner')->count();
        } finally {
            if ($admin->transactionLevel() > 0) {
                $admin->rollBack();
            }

            if ($process !== null && $result === null) {
                proc_terminate($process['handle']);
                proc_close($process['handle']);
            }

            // The two sessions committed for real, so the rows are removed by hand.
            $admin->table('barbershops')->delete();
            $admin->table('users')->where('id', $ownerId)->delete();
        }

        $this->assertSame('pura-navaja-2', $result['link']);
        $this->assertSame(['pura-navaja', 'pura-navaja-2'], $links);
        $this->assertSame(1, $owners);
    }

    /**
     * Start the registration of a barbershop in another process.
     *
     * @return array{handle: resource, pipes: array<int, resource>}
     */
    private function startWorker(string $name, string $ownerId): array
    {
        $handle = proc_open(
            [PHP_BINARY, base_path('tests/Support/Catalog/register_barbershop_worker.php'), $name, $ownerId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => 'barberhub_testing', 'DB_URL' => ''],
        );

        if (! is_resource($handle)) {
            throw new RuntimeException('The worker process could not be started.');
        }

        return ['handle' => $handle, 'pipes' => $pipes];
    }

    /**
     * Wait until another session is blocked on a transaction id, which is the first session holding its link.
     *
     * @param  resource  $handle
     */
    private function waitUntilTheWorkerWaitsForTheLock(ConnectionInterface $admin, $handle): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (microtime(true) < $deadline) {
            if ((int) $admin->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) {
                return;
            }

            if (! proc_get_status($handle)['running']) {
                $this->fail('The worker finished without waiting for the lock of the first registration.');
            }

            usleep(50_000);
        }

        $this->fail('The worker never waited for the lock of the first registration.');
    }

    /**
     * Wait for the worker and return what it printed.
     *
     * @param  array{handle: resource, pipes: array<int, resource>}  $process
     * @return array{id: string, link: string}
     */
    private function finishWorker(array $process): array
    {
        $output = (string) stream_get_contents($process['pipes'][1]);
        $errors = (string) stream_get_contents($process['pipes'][2]);
        $exitCode = proc_close($process['handle']);

        $this->assertSame(0, $exitCode, "The worker failed: {$errors}");

        /** @var array{id: string, link: string} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
