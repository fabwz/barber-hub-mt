<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\RentInstallments;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopBlockState;
use Src\Catalog\RentInstallments\Application\UseCases\UnblockBarbershopWithoutPendingRent;
use Src\Catalog\RentInstallments\Infrastructure\Persistence\Queries\DatabaseBarbershopBlockState;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/**
 * The daily blocking for unpaid rent on the real database (RF-58, PL-07, PL-11, PL-12): the day it blocks, that it is idempotent, that it
 * runs with no session, that one barbershop failing does not stop the others, the unblock and the technical record.
 */
final class BlockOverdueBarbershopsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2026-11-10 08:00:00-06:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    /** An installment due on the 10th, unpaid, does not block on the 10th and blocks on the 11th, with no days of grace (PL-07). */
    public function test_a_rent_due_on_the_10th_blocks_on_the_11th_and_not_before(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');

        $this->runOn('2026-11-10 08:00:00-06:00');
        $this->assertNull($this->blockedAt($id));

        $this->runOn('2026-11-11 00:10:00-06:00');
        $this->assertNotNull($this->blockedAt($id));
    }

    /** The limit is midnight of Costa Rica: at 05:59:59 UTC it is still the 10th there and nothing happens; at 06:00:00 UTC the barbershop is blocked. */
    public function test_the_midnight_of_costa_rica_is_the_limit(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');

        $this->runOn('2026-11-11 05:59:59+00:00');
        $this->assertNull($this->blockedAt($id));

        $this->runOn('2026-11-11 06:00:00+00:00');
        $this->assertSame('2026-11-11 06:00:00', $this->blockedAt($id));
    }

    /** Running the task twice leaves the same state, the same instant and one entry in the record. */
    public function test_running_twice_changes_nothing_and_records_once(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');

        $this->runOn('2026-11-11 00:10:00-06:00');
        $blockedAt = $this->blockedAt($id);
        $this->runOn('2026-11-12 00:10:00-06:00');

        $this->assertSame($blockedAt, $this->blockedAt($id));
        $this->assertCount(1, $this->record($id));
    }

    /** A barbershop with every installment paid, or none overdue, is not blocked and leaves nothing in the record. */
    public function test_a_barbershop_with_everything_paid_is_not_blocked(): void
    {
        $paid = $this->barbershop();
        $this->installment($paid, '2026-09-10', 'paid');
        $this->installment($paid, '2026-10-10', 'paid');
        $notDue = $this->barbershop();
        $this->installment($notDue, '2026-12-10');

        $this->runOn('2026-11-20 00:10:00-06:00');

        $this->assertNull($this->blockedAt($paid));
        $this->assertNull($this->blockedAt($notDue));
        $this->assertSame([], $this->record($paid));
        $this->assertSame([], $this->record($notDue));
    }

    /** Only the barbershop that owes is blocked; the one next to it is not (the state is per barbershop). */
    public function test_only_the_barbershop_that_owes_is_blocked(): void
    {
        $owes = $this->barbershop();
        $this->installment($owes, '2026-11-10');
        $current = $this->barbershop();
        $this->installment($current, '2026-11-10', 'paid');

        $this->runOn('2026-11-11 00:10:00-06:00');

        $this->assertNotNull($this->blockedAt($owes));
        $this->assertNull($this->blockedAt($current));
    }

    /** The block leaves its entry: the system as author, the date, and the change from none to the instant it was blocked (PL-12, SE-04). */
    public function test_the_block_leaves_an_entry_with_the_system_as_author(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');

        $this->runOn('2026-11-11 00:10:00-06:00');

        $entries = $this->record($id);
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['actor_user_id']);
        $this->assertSame(['barbershop.blocked', 'barbershop', $id], [$entries[0]['action'], $entries[0]['subject_type'], $entries[0]['subject_id']]);
        $this->assertEquals(['blocked_at' => ['previous' => null, 'new' => '2026-11-11T00:10:00-06:00']], json_decode($entries[0]['changes'], true));
        $this->assertSame('2026-11-11 06:10:00', (new DateTimeImmutable($entries[0]['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /** Paying everything unblocks in the same operation of the payment, with its entry; a month still owed keeps the block (PL-11, PL-12). */
    public function test_unblocking_happens_in_the_operation_that_clears_the_rent(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-10-10');
        $this->installment($id, '2026-11-10');
        $this->runOn('2026-11-11 00:10:00-06:00');
        $unblock = fn (): bool => $this->scope($id, fn (): bool => $this->app->make(UnblockBarbershopWithoutPendingRent::class)->execute($id, $this->clock->now()));

        $this->clock->moveTo(new DateTimeImmutable('2026-11-15 10:00:00-06:00'));
        $this->assertFalse($unblock());
        $this->assertNotNull($this->blockedAt($id));

        // The payment settles the installments and unblocks inside one transaction.
        $this->assertTrue($this->scope($id, function () use ($id): bool {
            DB::table('rent_installments')->update(['status' => 'paid']);

            return $this->app->make(UnblockBarbershopWithoutPendingRent::class)->execute($id, $this->clock->now());
        }));

        $this->assertNull($this->blockedAt($id));
        $this->assertFalse($unblock());
        $entries = $this->record($id);
        $this->assertSame(['barbershop.blocked', 'barbershop.unblocked'], array_column($entries, 'action'));
        $this->assertNull($entries[1]['actor_user_id']);
        $this->assertEquals(['blocked_at' => ['previous' => '2026-11-11T00:10:00-06:00', 'new' => null]], json_decode($entries[1]['changes'], true));
    }

    /** If the payment fails after the unblock the whole operation rolls back: the barbershop stays blocked and nothing is recorded. */
    public function test_a_payment_that_rolls_back_leaves_the_barbershop_blocked(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');
        $this->runOn('2026-11-11 00:10:00-06:00');
        $this->clock->moveTo(new DateTimeImmutable('2026-11-12 10:00:00-06:00'));

        try {
            $this->scope($id, function () use ($id): void {
                DB::table('rent_installments')->update(['status' => 'paid']);
                $this->app->make(UnblockBarbershopWithoutPendingRent::class)->execute($id, $this->clock->now());

                throw new RuntimeException('The payment failed after the unblock.');
            });
        } catch (RuntimeException) {
            // Expected: if it did not fail, the unblock would have stuck and the assertions below would not hold.
        }

        $this->assertNotNull($this->blockedAt($id));
        $this->assertSame(['barbershop.blocked'], array_column($this->record($id), 'action'));
        $this->assertSame(1, $this->scope($id, fn (): int => DB::table('rent_installments')->where('status', 'pending')->count()));
    }

    /** One barbershop that fails does not stop the others: the command blocks the rest, lists the one that failed and ends with an error. */
    public function test_a_barbershop_that_fails_does_not_stop_the_others(): void
    {
        $first = $this->barbershop();
        $failing = $this->barbershop();
        $last = $this->barbershop();

        foreach ([$first, $failing, $last] as $id) {
            $this->installment($id, '2026-11-10');
        }
        $this->app->bind(BarbershopBlockState::class, fn (): BarbershopBlockState => $this->stateThatFailsOn($failing));
        $this->clock->moveTo(new DateTimeImmutable('2026-11-11 00:10:00-06:00'));

        $this->assertSame(1, Artisan::call('rent:block-overdue-barbershops'));

        $this->assertStringContainsString($failing, Artisan::output());
        $this->assertNotNull($this->blockedAt($first));
        $this->assertNull($this->blockedAt($failing));
        $this->assertSame([], $this->record($failing));
        $this->assertNotNull($this->blockedAt($last));
    }

    /** The command works with no session and no request: nobody is authenticated and, outside the scope of a barbershop, no row of its tables is within reach. */
    public function test_the_command_runs_without_a_session(): void
    {
        $first = $this->barbershop();
        $second = $this->barbershop();
        $this->installment($first, '2026-11-10');
        $this->installment($second, '2026-11-10');
        $this->clock->moveTo(new DateTimeImmutable('2026-11-11 00:10:00-06:00'));

        $this->assertFalse(Auth::check());
        $this->assertSame(0, Artisan::call('rent:block-overdue-barbershops'));

        $this->assertFalse(Auth::check());
        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('rent_installments')->count());
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertNotNull($this->blockedAt($first));
        $this->assertNotNull($this->blockedAt($second));
    }

    /** The technical record accepts the system as author and still only grows: its entries cannot be changed or deleted (SE-04). */
    public function test_an_entry_of_the_system_cannot_be_changed_or_deleted(): void
    {
        $id = $this->barbershop();
        $this->installment($id, '2026-11-10');
        $this->runOn('2026-11-11 00:10:00-06:00');

        foreach (['UPDATE audit_log SET action = \'x\'', 'DELETE FROM audit_log'] as $statement) {
            try {
                $this->scope($id, fn () => DB::statement($statement));
                $this->fail('The record was changed.');
            } catch (QueryException) {
                $this->assertCount(1, $this->record($id));
            }
        }
    }

    /** The state is one nullable column of the barbershop; the author of the record is nullable and the pending months are not stored anywhere. */
    public function test_the_state_is_a_nullable_instant_and_the_system_author_is_null(): void
    {
        $schema = Schema::connection('pgsql_admin');
        $blockedAt = collect($schema->getColumns('barbershops'))->firstWhere('name', 'blocked_at');
        $actor = collect($schema->getColumns('audit_log'))->firstWhere('name', 'actor_user_id');

        $this->assertNotNull($blockedAt);
        $this->assertTrue($blockedAt['nullable']);
        $this->assertSame('timestamptz', $blockedAt['type_name']);
        $this->assertNotNull($actor);
        $this->assertTrue($actor['nullable']);
        $this->assertFalse($schema->hasColumn('barbershops', 'pending_months'));
        $this->assertFalse($schema->hasColumn('rent_installments', 'pending_months'));
    }

    /** Run the command at an instant. */
    private function runOn(string $instant): void
    {
        $this->clock->moveTo(new DateTimeImmutable($instant));
        $this->assertSame(0, Artisan::call('rent:block-overdue-barbershops'));
    }

    /** Insert a barbershop as the application role does. */
    private function barbershop(): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => 'Barbería '.$id, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'b-'.$id]);

        return $id;
    }

    private function installment(string $barbershopId, string $dueOn, string $status = 'pending'): void
    {
        $this->scope($barbershopId, fn () => DB::table('rent_installments')->insert(['barbershop_id' => $barbershopId, 'amount' => 25000, 'due_on' => $dueOn, 'status' => $status]));
    }

    /** When the barbershop was blocked, in UTC as text, or null. */
    private function blockedAt(string $barbershopId): ?string
    {
        $value = DB::table('barbershops')->where('id', $barbershopId)->value('blocked_at');

        return $value === null ? null : (new DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The entries of the technical record of a barbershop, in order, read as that barbershop.
     *
     * @return list<array<string, mixed>>
     */
    private function record(string $barbershopId): array
    {
        return array_values($this->scope($barbershopId, fn (): array => DB::table('audit_log')->orderBy('created_at')->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all()));
    }

    /** The real state, except that blocking the given barbershop throws. */
    private function stateThatFailsOn(string $failing): BarbershopBlockState
    {
        $real = new DatabaseBarbershopBlockState;

        return new readonly class($real, $failing) implements BarbershopBlockState
        {
            public function __construct(private BarbershopBlockState $real, private string $failing) {}

            public function lock(string $barbershopId): ?DateTimeImmutable
            {
                return $this->real->lock($barbershopId);
            }

            public function block(string $barbershopId, DateTimeImmutable $at): bool
            {
                return $barbershopId === $this->failing ? throw new RuntimeException('The barbershop cannot be blocked.') : $this->real->block($barbershopId, $at);
            }

            public function unblock(string $barbershopId): bool
            {
                return $this->real->unblock($barbershopId);
            }
        };
    }

    /**
     * Run as one barbershop, which is what the daily task does for each one.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function scope(string $barbershopId, Closure $operation): mixed
    {
        return $this->app->make(BarbershopProcessScope::class)->run($barbershopId, $operation);
    }
}
