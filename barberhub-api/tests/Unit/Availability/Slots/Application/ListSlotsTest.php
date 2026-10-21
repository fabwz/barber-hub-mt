<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Application\UseCases\ListAlternativeSlots;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\Exceptions\ServiceNotBookableException;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\Availability\InMemoryBarbershopHours;
use Tests\Support\Availability\InMemoryBlockedIntervals;
use Tests\Support\Availability\InMemoryServiceCatalog;
use Tests\Support\Availability\InMemoryStaffRoster;

final class ListSlotsTest extends TestCase
{
    private const string HAIRCUT = '0192f3a0-0000-7000-8000-0000000000a1';

    private const string MAURICIO = '0192f3a0-0000-7000-8000-000000000001';

    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000002';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000003';

    /** A Tuesday, and a "now" well before it. */
    private const string DAY = '2030-03-05';

    private const string NOW = '2030-03-01 08:00';

    /** An unknown service cannot be booked. */
    public function test_an_unknown_service_is_not_bookable(): void
    {
        $this->expectException(ServiceNotBookableException::class);

        $this->available([self::MAURICIO => ['09:00', '10:00']])->execute('0192f3a0-0000-7000-8000-0000000000ff', self::DAY, null, $this->now());
    }

    /** A date that is not a date is refused before anything is read. */
    public function test_an_invalid_date_is_refused(): void
    {
        $roster = new InMemoryStaffRoster([]);

        try {
            $this->useCase($roster, new InMemoryBlockedIntervals)->execute(self::HAIRCUT, '05/03/2030', null, $this->now());
            $this->fail('An invalid date was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('availability.date_invalid', $exception->translationKey());
            $this->assertSame(0, $roster->reads);
        }
    }

    /** A closed day has no spaces and the staff and the reservations are not even read. */
    public function test_a_closed_day_stops_before_reading_staff_and_reservations(): void
    {
        $roster = new InMemoryStaffRoster([$this->workday(self::MAURICIO, '09:00', '19:00')]);
        $blocked = new InMemoryBlockedIntervals;

        $sunday = $this->useCase($roster, $blocked)->execute(self::HAIRCUT, '2030-03-03', null, $this->now());

        $this->assertSame([], $sunday);
        $this->assertSame(0, $roster->reads);
        $this->assertSame(0, $blocked->reads);
    }

    /** Without a collaborator the spaces of everybody who offers the service come together, ordered by start. */
    public function test_lists_the_spaces_of_all_collaborators_ordered_by_start(): void
    {
        $slots = $this->available([self::MAURICIO => ['09:00', '10:00'], self::DANIELA => ['09:30', '10:30']])
            ->execute(self::HAIRCUT, self::DAY, null, $this->now());

        $this->assertSame(
            [['09:00', self::MAURICIO], ['09:15', self::MAURICIO], ['09:30', self::DANIELA], ['09:45', self::DANIELA]],
            array_map(fn (Slot $slot): array => [$slot->start->format('H:i'), $slot->collaboratorId->value], $slots),
        );
    }

    /** With a collaborator only theirs come back, and one who does not offer the service has none (CO-01). */
    public function test_filters_by_collaborator(): void
    {
        $available = $this->available([self::MAURICIO => ['09:00', '10:00'], self::DANIELA => ['09:30', '10:30']]);

        $this->assertSame(
            ['09:30', '09:45'],
            array_map(fn (Slot $slot): string => $slot->start->format('H:i'), $available->execute(self::HAIRCUT, self::DAY, self::DANIELA, $this->now())),
        );
        $this->assertSame([], $available->execute(self::HAIRCUT, self::DAY, self::KEVIN, $this->now()));
    }

    /** The reservations of each collaborator only block that collaborator. */
    public function test_reservations_block_only_their_collaborator(): void
    {
        $blocked = new InMemoryBlockedIntervals([self::MAURICIO => [TimeInterval::between($this->at(self::DAY.' 09:00'), $this->at(self::DAY.' 09:50'))]]);

        $slots = $this->useCase(
            new InMemoryStaffRoster([$this->workday(self::MAURICIO, '09:00', '11:00'), $this->workday(self::DANIELA, '09:00', '11:00')]),
            $blocked,
        )->execute(self::HAIRCUT, self::DAY, null, $this->now());

        $starts = array_map(fn (Slot $slot): string => $slot->collaboratorId->value === self::MAURICIO ? 'M'.$slot->start->format('H:i') : 'D'.$slot->start->format('H:i'), $slots);

        $this->assertNotContains('M09:00', $starts);
        $this->assertNotContains('M09:45', $starts);
        $this->assertContains('M10:00', $starts);
        $this->assertContains('D09:00', $starts);
    }

    /** However many collaborators there are, the staff and the reservations are read once each (no N+1). */
    public function test_reads_each_port_once_whatever_the_number_of_collaborators(): void
    {
        $roster = new InMemoryStaffRoster([
            $this->workday(self::MAURICIO, '09:00', '19:00'),
            $this->workday(self::DANIELA, '09:00', '19:00'),
            $this->workday(self::KEVIN, '09:00', '19:00'),
        ]);
        $blocked = new InMemoryBlockedIntervals;

        $this->useCase($roster, $blocked)->execute(self::HAIRCUT, self::DAY, null, $this->now());

        $this->assertSame(1, $roster->reads);
        $this->assertSame(1, $blocked->reads);
    }

    /** The alternatives are the 3 free spaces of the day closest to the requested start, even when that one is taken. */
    public function test_alternatives_are_the_three_closest_free_spaces_of_the_day(): void
    {
        $blocked = new InMemoryBlockedIntervals([self::MAURICIO => [TimeInterval::between($this->at(self::DAY.' 10:00'), $this->at(self::DAY.' 10:50'))]]);
        $available = $this->useCase(
            new InMemoryStaffRoster([$this->workday(self::MAURICIO, '09:00', '13:00')]),
            $blocked,
        );

        $alternatives = new ListAlternativeSlots($available, new SlotCalculator)->execute(self::HAIRCUT, self::MAURICIO, $this->at(self::DAY.' 10:00'), $this->now());

        // 09:15 (45 minutes away) and the tie of 09:00 and 11:00 (60 away): 09:30 to 10:45 touch the reservation.
        $this->assertSame(['09:00', '09:15', '11:00'], array_map(fn (Slot $slot): string => $slot->start->format('H:i'), $alternatives));
    }

    /** @param array<string, array{string, string}> $shifts The shift of each collaborator on Tuesday. */
    private function available(array $shifts): ListAvailableSlots
    {
        return $this->useCase(
            new InMemoryStaffRoster(array_map(fn (string $id, array $shift): StaffWorkday => $this->workday($id, $shift[0], $shift[1]), array_keys($shifts), $shifts)),
            new InMemoryBlockedIntervals,
        );
    }

    /** The use case over a barbershop that opens on Tuesdays from 9:00 to 19:00. */
    private function useCase(InMemoryStaffRoster $roster, InMemoryBlockedIntervals $blocked): ListAvailableSlots
    {
        return new ListAvailableSlots(
            new InMemoryServiceCatalog([self::HAIRCUT => 30]),
            new InMemoryBarbershopHours([2 => [$this->range('09:00', '19:00')]]),
            $roster,
            $blocked,
            new SlotCalculator,
        );
    }

    private function workday(string $id, string $from, string $to): StaffWorkday
    {
        return new StaffWorkday($id, [$this->range($from, $to)], []);
    }

    private function range(string $from, string $to): TimeRange
    {
        return TimeRange::between(new TimeOfDay($from), new TimeOfDay($to));
    }

    private function now(): DateTimeImmutable
    {
        return $this->at(self::NOW);
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
