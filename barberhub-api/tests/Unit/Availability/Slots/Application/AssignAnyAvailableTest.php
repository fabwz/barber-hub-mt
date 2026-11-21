<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Application\UseCases\AssignAnyAvailable;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\Services\AnyAvailableAssigner;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\Availability\InMemoryBarbershopHours;
use Tests\Support\Availability\InMemoryBlockedIntervals;
use Tests\Support\Availability\InMemoryDailyReservationCounts;
use Tests\Support\Availability\InMemoryServiceCatalog;
use Tests\Support\Availability\InMemoryStaffRoster;

/** Who takes a space when the customer accepts "any available": the fewest reservations that day, then the oldest registration (DI-04, CO-01). */
final class AssignAnyAvailableTest extends TestCase
{
    private const string HAIRCUT = '0192f3a0-0000-7000-8000-0000000000a1';

    /** The roster lists them from the oldest to the newest registration. */
    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000001';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000002';

    private const string LUIS = '0192f3a0-0000-7000-8000-000000000003';

    /** A Tuesday at 10:00; today is the Friday before. */
    private const string START = '2030-03-05 10:00';

    private const string NOW = '2030-03-01 08:00';

    /** The collaborator with fewer reservations that day takes the space. */
    public function test_the_collaborator_with_fewer_reservations_takes_the_space(): void
    {
        $assigned = $this->assignment([self::DANIELA, self::KEVIN, self::LUIS], [self::DANIELA => 3, self::KEVIN => 1, self::LUIS => 2])
            ->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW));

        $this->assertSame(self::KEVIN, $assigned);
    }

    /** On a tie the one registered first, the first of the roster, takes it. */
    public function test_a_tie_goes_to_the_oldest_registration(): void
    {
        $assigned = $this->assignment([self::KEVIN, self::DANIELA, self::LUIS], [self::DANIELA => 2, self::KEVIN => 2, self::LUIS => 2])
            ->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW));

        $this->assertSame(self::KEVIN, $assigned, 'Kevin comes first in the roster, so he is the oldest.');
    }

    /** Someone without any reservation counts as zero. */
    public function test_whoever_has_no_reservations_counts_as_zero(): void
    {
        $assigned = $this->assignment([self::DANIELA, self::KEVIN], [self::DANIELA => 1])
            ->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW));

        $this->assertSame(self::KEVIN, $assigned);
    }

    /** Only who is free at that start is a candidate: Kevin, with fewer reservations, is busy then. */
    public function test_only_who_is_free_at_that_start_is_a_candidate(): void
    {
        $busy = [self::KEVIN => [TimeInterval::between($this->at('2030-03-05 09:30'), $this->at('2030-03-05 10:35'))]];

        $assigned = $this->assignment([self::DANIELA, self::KEVIN], [self::DANIELA => 5, self::KEVIN => 0], $busy)
            ->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW));

        $this->assertSame(self::DANIELA, $assigned);
    }

    /** Only the staff the roster lists is considered, and the roster has only who offers the service: Luis, who does not, never takes it even with no reservations. */
    public function test_only_the_staff_that_offers_the_service_is_considered(): void
    {
        $assignment = $this->assignment([self::DANIELA, self::KEVIN], [self::DANIELA => 4, self::KEVIN => 6, self::LUIS => 0]);

        $this->assertSame(self::DANIELA, $assignment->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW)));
        $this->assertSame([self::DANIELA, self::KEVIN], $assignment->candidates(self::HAIRCUT, $this->at(self::START)));
    }

    /** Without staff nobody is assigned. */
    public function test_without_staff_nobody_is_assigned(): void
    {
        $assignment = $this->assignment([], []);

        $this->assertNull($assignment->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW)));
        $this->assertSame([], $assignment->candidates(self::HAIRCUT, $this->at(self::START)));
    }

    /** If everyone is busy at that start nobody is assigned. */
    public function test_nobody_is_assigned_when_everyone_is_busy(): void
    {
        $busy = [
            self::DANIELA => [TimeInterval::between($this->at('2030-03-05 10:00'), $this->at('2030-03-05 11:00'))],
            self::KEVIN => [TimeInterval::between($this->at('2030-03-05 09:00'), $this->at('2030-03-05 12:00'))],
        ];

        $this->assertNull($this->assignment([self::DANIELA, self::KEVIN], [], $busy)->forStart(self::HAIRCUT, $this->at(self::START), $this->at(self::NOW)));
    }

    /** One space per start comes out, each with its own assignment, and the counts are read once whatever the number of spaces. */
    public function test_one_space_per_start_is_listed_with_its_assignment_and_the_counts_are_read_once(): void
    {
        $counts = new InMemoryDailyReservationCounts([self::DANIELA => 2, self::KEVIN => 1]);
        $busy = [self::KEVIN => [TimeInterval::between($this->at('2030-03-05 10:00'), $this->at('2030-03-05 11:00'))]];
        $assignment = $this->assignment([self::DANIELA, self::KEVIN], [], $busy, $counts);
        $slots = $this->available([self::DANIELA, self::KEVIN], $busy)->execute(self::HAIRCUT, '2030-03-05', null, $this->at(self::NOW));

        $assigned = $assignment->onePerStart(self::HAIRCUT, CalendarDate::of('2030-03-05'), $slots, $this->at(self::NOW));

        $starts = array_map(fn (Slot $slot): string => $slot->start->format('H:i'), $assigned);
        $this->assertSame(array_values(array_unique($starts)), $starts, 'No start may repeat.');
        $byStart = array_combine($starts, array_map(fn (Slot $slot): string => $slot->collaboratorId->value, $assigned));
        $this->assertSame(self::KEVIN, $byStart['11:00']);
        $this->assertSame(self::DANIELA, $byStart['09:30'], 'Kevin is busy from 10:00.');
        $this->assertSame(1, $counts->reads);
    }

    /**
     * @param  list<string>  $staff  Who offers the service and works that day, from the oldest to the newest.
     * @param  array<string, int>  $reservations
     * @param  array<string, list<TimeInterval>>  $busy
     */
    private function assignment(array $staff, array $reservations, array $busy = [], ?InMemoryDailyReservationCounts $counts = null): AssignAnyAvailable
    {
        return new AssignAnyAvailable(
            $this->available($staff, $busy),
            $this->roster($staff),
            $counts ?? new InMemoryDailyReservationCounts($reservations),
            new AnyAvailableAssigner,
        );
    }

    /**
     * @param  list<string>  $staff
     * @param  array<string, list<TimeInterval>>  $busy
     */
    private function available(array $staff, array $busy): ListAvailableSlots
    {
        return new ListAvailableSlots(
            new InMemoryServiceCatalog([self::HAIRCUT => 60]),
            new InMemoryBarbershopHours([2 => [TimeRange::between(new TimeOfDay('09:00'), new TimeOfDay('19:00'))]]),
            $this->roster($staff),
            new InMemoryBlockedIntervals($busy),
            new SlotCalculator,
        );
    }

    /** @param list<string> $staff */
    private function roster(array $staff): InMemoryStaffRoster
    {
        return new InMemoryStaffRoster(array_map(
            fn (string $id): StaffWorkday => new StaffWorkday($id, [TimeRange::between(new TimeOfDay('09:00'), new TimeOfDay('19:00'))], []),
            $staff,
        ));
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
