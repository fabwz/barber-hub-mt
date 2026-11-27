<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Infrastructure\Absences\AbsencesBlockedIntervals;
use Src\Availability\Slots\Infrastructure\Reservations\ReservationsBlockedIntervals;
use Src\Availability\Slots\Infrastructure\ReservationsAndOffersBlockedIntervals;
use Src\Availability\Slots\Infrastructure\Waitlist\OffersBlockedIntervals;
use Src\Catalog\Absences\Application\UseCases\ListAbsenceIntervals;
use Src\Catalog\Absences\Domain\Entities\Absence;
use Src\Catalog\Absences\Domain\ValueObjects\CollaboratorId as AbsenceCollaboratorId;
use Src\Reservations\Reservations\Application\Contracts\BlockedIntervalReader;
use Src\Reservations\Reservations\Application\UseCases\ListBlockedIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\OfferedSpaceReader;
use Src\Waitlist\Offers\Application\UseCases\ListOfferedSpaces;
use Tests\Support\Catalog\InMemoryAbsenceRepository;

/** What a collaborator holds is what their reservations hold plus the whole space of their pending offers, so an offered space does not show free (DI-05). */
final class ReservationsAndOffersBlockedIntervalsTest extends TestCase
{
    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000001';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000002';

    /** The reservations and the offers of a collaborator come out together, ordered by start. */
    public function test_the_time_of_reservations_and_offers_comes_out_together_ordered(): void
    {
        $held = $this->blocked(
            [self::DANIELA => [self::space('09:00', '09:50'), self::space('15:00', '15:50')]],
            [self::DANIELA => [self::space('10:00', '10:50')]],
        )->between([self::DANIELA], self::space('08:00', '19:00'), self::now());

        $this->assertSame(['09:00', '10:00', '15:00'], array_map(fn (TimeInterval $interval): string => $interval->start->format('H:i'), $held[self::DANIELA]));
    }

    /** A collaborator with only an offer, or only reservations, keeps what they have, and one with nothing is absent. */
    public function test_each_collaborator_keeps_what_they_hold(): void
    {
        $held = $this->blocked(
            [self::DANIELA => [self::space('09:00', '09:50')]],
            [self::KEVIN => [self::space('10:00', '10:50')]],
        )->between([self::DANIELA, self::KEVIN, '0192f3a0-0000-7000-8000-000000000003'], self::space('08:00', '19:00'), self::now());

        $this->assertSame([self::DANIELA, self::KEVIN], array_keys($held));
        $this->assertCount(1, $held[self::DANIELA]);
        $this->assertCount(1, $held[self::KEVIN]);
    }

    /** Intervals that overlap, a reservation and an offer, are both kept: the availability needs only that something holds the time. */
    public function test_overlapping_intervals_are_both_kept(): void
    {
        $held = $this->blocked(
            [self::DANIELA => [self::space('10:00', '10:50')]],
            [self::DANIELA => [self::space('10:30', '11:20')]],
        )->between([self::DANIELA], self::space('08:00', '19:00'), self::now());

        $this->assertCount(2, $held[self::DANIELA]);
    }

    /** The time a collaborator is absent is held too, so no space is offered inside it (DI-01, CO-06), and only for that collaborator. */
    public function test_the_absence_of_a_collaborator_is_held_for_them_only(): void
    {
        $absences = new InMemoryAbsenceRepository;
        $absences->save(Absence::reconstitute($absences->nextId(), new AbsenceCollaboratorId(self::DANIELA), self::space('14:00', '16:00'), null));

        $held = $this->blocked([self::DANIELA => [self::space('09:00', '09:50')]], [], $absences)
            ->between([self::DANIELA, self::KEVIN], self::space('08:00', '19:00'), self::now());

        $this->assertSame(['09:00', '14:00'], array_map(fn (TimeInterval $interval): string => $interval->start->format('H:i'), $held[self::DANIELA]));
        $this->assertArrayNotHasKey(self::KEVIN, $held);
    }

    /** Nothing held anywhere is an empty answer. */
    public function test_nothing_held_is_an_empty_answer(): void
    {
        $this->assertSame([], $this->blocked([], [])->between([self::DANIELA], self::space('08:00', '19:00'), self::now()));
    }

    /**
     * The two sources over fixed answers.
     *
     * @param  array<string, list<TimeInterval>>  $reservations
     * @param  array<string, list<TimeInterval>>  $offers
     */
    private function blocked(array $reservations, array $offers, ?InMemoryAbsenceRepository $absences = null): ReservationsAndOffersBlockedIntervals
    {
        $reservationReader = new class($reservations) implements BlockedIntervalReader
        {
            /** @param  array<string, list<TimeInterval>>  $held */
            public function __construct(private readonly array $held) {}

            public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
            {
                return array_intersect_key($this->held, array_flip($collaboratorIds));
            }
        };
        $offerReader = new class($offers) implements OfferedSpaceReader
        {
            /** @param  array<string, list<TimeInterval>>  $held */
            public function __construct(private readonly array $held) {}

            public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
            {
                return array_intersect_key($this->held, array_flip($collaboratorIds));
            }
        };

        return new ReservationsAndOffersBlockedIntervals(
            new ReservationsBlockedIntervals(new ListBlockedIntervals($reservationReader)),
            new OffersBlockedIntervals(new ListOfferedSpaces($offerReader)),
            new AbsencesBlockedIntervals(new ListAbsenceIntervals($absences ?? new InMemoryAbsenceRepository)),
        );
    }

    private static function space(string $from, string $to): TimeInterval
    {
        return TimeInterval::between(self::at($from), self::at($to));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
