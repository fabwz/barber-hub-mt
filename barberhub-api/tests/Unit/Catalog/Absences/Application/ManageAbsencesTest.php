<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Absences\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Absences\Application\DTOs\RegisterAbsenceCommand;
use Src\Catalog\Absences\Application\UseCases\DeleteAbsence;
use Src\Catalog\Absences\Application\UseCases\ListAbsenceIntervals;
use Src\Catalog\Absences\Application\UseCases\ListAbsences;
use Src\Catalog\Absences\Application\UseCases\RegisterAbsence;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceCollaboratorInactiveException;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceNotFoundException;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceWithReservationsException;
use Src\Catalog\Absences\Domain\Exceptions\InvalidAbsenceException;
use Src\Catalog\Absences\Domain\ValueObjects\ConflictingReservation;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Tests\Support\Catalog\InMemoryAbsenceCollaborators;
use Tests\Support\Catalog\InMemoryAbsenceRepository;
use Tests\Support\Catalog\InMemoryReservationConflicts;
use Tests\Support\Shared\RecordingTransactionRunner;

/** The owner blocks the agenda of a collaborator by hours or by days (RF-07, CO-06, DI-01). */
final class ManageAbsencesTest extends TestCase
{
    private const string KEVIN = '0192f0c4-0000-7000-8000-000000000001';

    private InMemoryAbsenceRepository $absences;

    private InMemoryAbsenceCollaborators $collaborators;

    private InMemoryReservationConflicts $conflicts;

    private RegisterAbsence $register;

    private DateTimeImmutable $now;

    /** Wire the use case to the in-memory ports; "now" is 20 October 2026 at 10:00 in Costa Rica. */
    protected function setUp(): void
    {
        $this->absences = new InMemoryAbsenceRepository;
        $this->collaborators = new InMemoryAbsenceCollaborators;
        $this->collaborators->add(self::KEVIN);
        $this->conflicts = new InMemoryReservationConflicts;
        $this->register = new RegisterAbsence($this->absences, $this->collaborators, $this->conflicts, new RecordingTransactionRunner);
        $this->now = new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('America/Costa_Rica'));
    }

    /** Hours on one day become an interval in the time of Costa Rica. */
    public function test_hours_of_one_day_become_an_interval(): void
    {
        $absence = $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', '14:00', '16:00', null), $this->now);

        $this->assertSame('2026-11-05T14:00:00-06:00', $absence->interval->start->format(DATE_ATOM));
        $this->assertSame('2026-11-05T16:00:00-06:00', $absence->interval->end->format(DATE_ATOM));
        $this->assertSame([self::KEVIN], $this->collaborators->locked);
    }

    /** Without hours the days are whole: from the first midnight to the one after the last day. */
    public function test_days_without_hours_are_whole(): void
    {
        $absence = $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-09', '2026-11-13', null, null, ' Vacaciones '), $this->now);

        $this->assertSame('2026-11-09T00:00:00-06:00', $absence->interval->start->format(DATE_ATOM));
        $this->assertSame('2026-11-14T00:00:00-06:00', $absence->interval->end->format(DATE_ATOM));
        $this->assertSame('Vacaciones', $absence->reason?->value);
    }

    /** A reservation inside the range stops the save and the error lists it; nothing is stored. */
    public function test_a_reservation_inside_the_range_refuses_the_absence(): void
    {
        $this->conflicts->add(self::KEVIN, $this->reservation('r1', '2026-11-05 14:00', '2026-11-05 14:35'));

        try {
            $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', '14:00', '16:00', null), $this->now);
            $this->fail('The absence was saved over a reservation.');
        } catch (AbsenceWithReservationsException $exception) {
            $this->assertSame('ausencia-con-reservas', $exception->rule());
            $this->assertSame(['r1'], array_column($exception->problemMembers()['reservas'], 'id'));
            $this->assertSame(1, $exception->problemMembers()['total_reservas']);
        }

        $this->assertSame([], $this->absences->current($this->now));
    }

    /** Once the owner resolves the reservations the same request is saved. */
    public function test_after_resolving_the_reservations_the_absence_is_saved(): void
    {
        $this->conflicts->add(self::KEVIN, $this->reservation('r1', '2026-11-05 14:00', '2026-11-05 14:35'));
        $command = new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', '14:00', '16:00', null);

        try {
            $this->register->execute($command, $this->now);
        } catch (AbsenceWithReservationsException) {
            $this->conflicts->resolve(self::KEVIN, 'r1');
        }

        $this->register->execute($command, $this->now);

        $this->assertCount(1, $this->absences->current($this->now));
    }

    /** A reservation outside the range does not count. */
    public function test_a_reservation_outside_the_range_does_not_count(): void
    {
        $this->conflicts->add(self::KEVIN, $this->reservation('r1', '2026-11-05 12:00', '2026-11-05 12:35'));

        $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', '14:00', '16:00', null), $this->now);

        $this->assertCount(1, $this->absences->current($this->now));
    }

    /** An inactive collaborator is a conflict and an unknown one is not found. */
    public function test_inactive_and_unknown_collaborators_are_refused(): void
    {
        $this->collaborators->add('0192f0c4-0000-7000-8000-000000000002', false);

        $this->expectException(AbsenceCollaboratorInactiveException::class);
        $this->register->execute(new RegisterAbsenceCommand('0192f0c4-0000-7000-8000-000000000002', '2026-11-05', '2026-11-05', null, null, null), $this->now);
    }

    /** A collaborator that is not in the barbershop is not found. */
    public function test_unknown_collaborator_is_not_found(): void
    {
        $this->expectException(CollaboratorNotFoundException::class);
        $this->register->execute(new RegisterAbsenceCommand('0192f0c4-0000-7000-8000-000000000009', '2026-11-05', '2026-11-05', null, null, null), $this->now);
    }

    /**
     * Ranges that make no sense are refused with the attribute to blame.
     *
     * @param  array{0: string, 1: string, 2: ?string, 3: ?string}  $range
     */
    #[DataProvider('badRanges')]
    public function test_bad_ranges_are_refused(array $range, string $field): void
    {
        try {
            $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, ...$range, reason: null), $this->now);
            $this->fail('A bad range was accepted.');
        } catch (InvalidAbsenceException $exception) {
            $this->assertSame($field, $exception->field());
        }
    }

    /** @return array<string, array{0: array{0: string, 1: string, 2: ?string, 3: ?string}, 1: string}> */
    public static function badRanges(): array
    {
        return [
            'ends before it starts' => [['2026-11-06', '2026-11-05', null, null], 'fecha_hasta'],
            'same day, hours reversed' => [['2026-11-05', '2026-11-05', '16:00', '14:00'], 'hora_hasta'],
            'already over' => [['2026-10-19', '2026-10-19', null, null], 'fecha_hasta'],
            'only one hour' => [['2026-11-05', '2026-11-05', '14:00', null], 'hora_hasta'],
            'not a date' => [['2026-02-30', '2026-03-01', null, null], 'fecha_desde'],
            'not an hour' => [['2026-11-05', '2026-11-05', '25:00', '26:00'], 'hora_desde'],
        ];
    }

    /** The list keeps the absences that have not ended, earliest first, and removing one takes it off. */
    public function test_list_and_delete(): void
    {
        $later = $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-20', '2026-11-20', null, null, null), $this->now);
        $sooner = $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', null, null, null), $this->now);

        $this->assertSame([$sooner->id->value, $later->id->value], array_map(fn ($absence): string => $absence->id->value, (new ListAbsences($this->absences))->execute($this->now)));

        (new DeleteAbsence($this->absences))->execute($sooner->id->value);

        $this->assertCount(1, (new ListAbsences($this->absences))->execute($this->now));
    }

    /** Removing an absence that does not exist is not found. */
    public function test_deleting_an_unknown_absence_is_not_found(): void
    {
        $this->expectException(AbsenceNotFoundException::class);
        (new DeleteAbsence($this->absences))->execute('0192f0c4-0000-7000-8000-000000000099');
    }

    /** Only the intervals of the collaborators asked for, inside the window, are read for the availability. */
    public function test_intervals_for_the_availability(): void
    {
        $this->register->execute(new RegisterAbsenceCommand(self::KEVIN, '2026-11-05', '2026-11-05', '14:00', '16:00', null), $this->now);
        $read = new ListAbsenceIntervals($this->absences);
        $day = TimeInterval::between($this->at('2026-11-05 00:00'), $this->at('2026-11-06 00:00'));

        $this->assertCount(1, $read->execute([self::KEVIN], $day)[self::KEVIN]);
        $this->assertSame([], $read->execute(['0192f0c4-0000-7000-8000-000000000002'], $day));
        $this->assertSame([], $read->execute([self::KEVIN], TimeInterval::between($this->at('2026-11-06 00:00'), $this->at('2026-11-07 00:00'))));
        $this->assertSame([], $read->execute([], $day));
    }

    private function reservation(string $id, string $from, string $to): ConflictingReservation
    {
        return new ConflictingReservation($id, $this->at($from), $this->at($to), 'Corte clásico', 'Ana Quirós', 'confirmada');
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
