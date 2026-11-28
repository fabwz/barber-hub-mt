<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\RentInstallments\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Catalog\RentInstallments\Application\UseCases\BlockOverdueBarbershops;
use Src\Catalog\RentInstallments\Domain\ValueObjects\InstallmentStatus;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Tests\Support\Catalog\InMemoryRentBlocking;

/** The block for unpaid rent and the unblock when nothing is owed (RF-58, PL-07, PL-11, PL-12), with the ports in memory. */
final class BlockOverdueRentTest extends TestCase
{
    private const string PURA_NAVAJA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01';

    private const string FADE_LAB = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02';

    private const string DEGRADADOS = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03';

    /** An installment that falls due on the 10th is not overdue that day and blocks from the 11th, with no days of grace (PL-07). */
    public function test_a_rent_due_on_the_10th_blocks_on_the_11th_and_not_on_the_10th(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);

        $this->assertFalse($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-10 08:00:00-06:00')));
        $this->assertFalse($rent->isBlocked(self::PURA_NAVAJA));

        $this->assertTrue($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 08:00:00-06:00')));
        $this->assertTrue($rent->isBlocked(self::PURA_NAVAJA));
    }

    /** The limit is midnight of Costa Rica, whatever zone the instant carries: 23:59:59 of the 10th there is 05:59:59 UTC of the 11th. */
    public function test_the_limit_is_midnight_of_costa_rica(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);

        $this->assertFalse($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-10 23:59:59-06:00')));
        $this->assertFalse($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 05:59:59+00:00')));
        $this->assertTrue($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 06:00:00+00:00')));
    }

    /** Blocking twice changes nothing and leaves one entry in the technical record, not two. */
    public function test_blocking_twice_changes_nothing_and_records_once(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);

        $this->assertTrue($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 00:10:00-06:00')));
        $this->assertFalse($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-12 00:10:00-06:00')));

        $this->assertTrue($rent->isBlocked(self::PURA_NAVAJA));
        $this->assertCount(1, $rent->audit->entries());
    }

    /** A barbershop with everything paid, or with no overdue installment, is not blocked and leaves nothing in the record. */
    public function test_a_barbershop_with_everything_paid_is_not_blocked(): void
    {
        $rent = (new InMemoryRentBlocking)
            ->withInstallments(self::PURA_NAVAJA, ['2026-09-10', '2026-10-10'], InstallmentStatus::Paid)
            ->withInstallments(self::FADE_LAB, ['2026-12-10']);

        $this->assertFalse($rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-20 08:00:00-06:00')));
        $this->assertFalse($rent->blocker()->execute(self::FADE_LAB, new DateTimeImmutable('2026-11-20 08:00:00-06:00')));

        $this->assertFalse($rent->isBlocked(self::PURA_NAVAJA));
        $this->assertFalse($rent->isBlocked(self::FADE_LAB));
        $this->assertSame([], $rent->audit->entries());
    }

    /** The block is recorded with the system as author, the date, and the change from not blocked to the instant it was blocked (PL-12, SE-04). */
    public function test_the_block_is_recorded_with_the_system_as_author(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);
        $now = new DateTimeImmutable('2026-11-11 06:10:00+00:00');

        $rent->blocker()->execute(self::PURA_NAVAJA, $now);

        $entry = $rent->audit->entries()[0];
        $this->assertNull($entry->actor);
        $this->assertSame(AuditAction::BarbershopBlocked, $entry->action);
        $this->assertSame(['barbershop', self::PURA_NAVAJA], [$entry->subjectType, $entry->subjectId]);
        $this->assertSame($now, $entry->at);
        $this->assertSame(['blocked_at' => ['previous' => null, 'new' => '2026-11-11T00:10:00-06:00']], $entry->changes);
    }

    /** Once nothing is pending the barbershop is unblocked, with its entry; the previous value is the instant it was blocked (PL-11, PL-12). */
    public function test_paying_everything_unblocks_and_records_it(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-10-10', '2026-11-10']);
        $rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 00:10:00-06:00'));
        $rent->payAll(self::PURA_NAVAJA);

        $this->assertTrue($rent->unblocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-15 10:00:00-06:00')));

        $this->assertFalse($rent->isBlocked(self::PURA_NAVAJA));
        $this->assertCount(2, $rent->audit->entries());
        $entry = $rent->audit->entries()[1];
        $this->assertNull($entry->actor);
        $this->assertSame(AuditAction::BarbershopUnblocked, $entry->action);
        $this->assertSame(['blocked_at' => ['previous' => '2026-11-11T00:10:00-06:00', 'new' => null]], $entry->changes);
    }

    /** With a month still owed, or when it was never blocked, unblocking changes nothing and records nothing. */
    public function test_unblocking_with_pending_months_or_when_not_blocked_does_nothing(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-10-10', '2026-11-10'])->withInstallments(self::FADE_LAB, ['2026-12-10']);
        $rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 00:10:00-06:00'));

        $this->assertFalse($rent->unblocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-15 10:00:00-06:00')));
        $this->assertTrue($rent->isBlocked(self::PURA_NAVAJA));

        $this->assertFalse($rent->unblocker()->execute(self::FADE_LAB, new DateTimeImmutable('2026-11-15 10:00:00-06:00')));
        $this->assertCount(1, $rent->audit->entries());
    }

    /** Unblocking twice leaves one entry. */
    public function test_unblocking_twice_records_once(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);
        $rent->blocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-11 00:10:00-06:00'));
        $rent->payAll(self::PURA_NAVAJA);

        $this->assertTrue($rent->unblocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-12 10:00:00-06:00')));
        $this->assertFalse($rent->unblocker()->execute(self::PURA_NAVAJA, new DateTimeImmutable('2026-11-12 10:01:00-06:00')));

        $this->assertCount(2, $rent->audit->entries());
    }

    /** The daily task blocks every barbershop that owes, and one that fails does not stop the others: it is reported (RF-58). */
    public function test_a_barbershop_that_fails_does_not_stop_the_others(): void
    {
        $rent = (new InMemoryRentBlocking)
            ->withInstallments(self::PURA_NAVAJA, ['2026-11-10'])
            ->withInstallments(self::FADE_LAB, ['2026-11-10'])
            ->withInstallments(self::DEGRADADOS, ['2026-11-10'])
            ->failingOn(self::FADE_LAB);

        $result = (new BlockOverdueBarbershops($rent, $rent->blocker()))->execute(new DateTimeImmutable('2026-11-11 00:10:00-06:00'));

        $this->assertSame(2, $result->blocked);
        $this->assertSame([self::FADE_LAB], $result->failedBarbershopIds);
        $this->assertTrue($rent->isBlocked(self::PURA_NAVAJA));
        $this->assertFalse($rent->isBlocked(self::FADE_LAB));
        $this->assertTrue($rent->isBlocked(self::DEGRADADOS));
    }

    /** Running the daily task twice gives the same result: the second run blocks no one. */
    public function test_the_daily_task_is_idempotent(): void
    {
        $rent = (new InMemoryRentBlocking)->withInstallments(self::PURA_NAVAJA, ['2026-11-10']);
        $task = new BlockOverdueBarbershops($rent, $rent->blocker());

        $this->assertSame(1, $task->execute(new DateTimeImmutable('2026-11-11 00:10:00-06:00'))->blocked);
        $this->assertSame(0, $task->execute(new DateTimeImmutable('2026-11-11 00:10:00-06:00'))->blocked);
        $this->assertCount(1, $rent->audit->entries());
    }
}
