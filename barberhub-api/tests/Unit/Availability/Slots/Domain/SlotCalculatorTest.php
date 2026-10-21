<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\CollaboratorId;
use Src\Availability\Slots\Domain\ValueObjects\DayAgenda;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final class SlotCalculatorTest extends TestCase
{
    private const string MAURICIO = '0192f3a0-0000-7000-8000-000000000001';

    /** A Tuesday. */
    private const string DAY = '2030-03-05';

    private const string EARLY = '2030-03-01 08:00';

    /** The example of the requirements: 9:00 to 19:00, a break from 12:00 to 13:00, and 60 + 5 minutes (RF-10). */
    public function test_a_tuesday_of_mauricio_has_the_expected_first_and_last_spaces(): void
    {
        $slots = $this->starts($this->calculate(
            open: [['09:00', '19:00']],
            shifts: [['09:00', '19:00']],
            breaks: [['12:00', '13:00']],
            service: 60,
        ));

        $this->assertSame('09:00', $slots[0]);
        $this->assertContains('10:45', $slots);
        $this->assertNotContains('11:00', $slots);
        $this->assertNotContains('12:45', $slots);
        $this->assertContains('13:00', $slots);
        $this->assertSame('17:45', end($slots));
        $this->assertCount(8 + 20, $slots);
    }

    /**
     * Every rule of DI-01 and DI-02 on a shift from 9:00 to 11:00 and a service of 30 + 5 minutes, which fits from 9:00 to 10:15.
     *
     * @param  list<array{string, string}>  $open
     * @param  list<array{string, string}>  $shifts
     * @param  list<array{string, string}>  $breaks
     * @param  list<array{string, string}>  $blocked  Instants of the day.
     * @param  list<string>  $expected
     */
    #[DataProvider('agendas')]
    public function test_spaces_follow_the_rules_of_the_agenda(array $open, array $shifts, array $breaks, array $blocked, array $expected): void
    {
        $slots = $this->calculate($open, $shifts, $breaks, 30, blocked: array_map(
            fn (array $held): TimeInterval => TimeInterval::between($this->at(self::DAY.' '.$held[0]), $this->at(self::DAY.' '.$held[1])),
            $blocked,
        ));

        $this->assertSame($expected, $this->starts($slots));
    }

    /** @return array<string, array{list<array{string, string}>, list<array{string, string}>, list<array{string, string}>, list<array{string, string}>, list<string>}> */
    public static function agendas(): array
    {
        $day = [['09:00', '11:00']];
        $all = ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15'];

        return [
            'everything free' => [$day, $day, [], [], $all],
            'barbershop closed' => [[], $day, [], [], []],
            'barbershop open only until 10:00' => [[['09:00', '10:00']], $day, [], [], ['09:00', '09:15']],
            'collaborator without a shift' => [$day, [], [], [], []],
            'a service that fits exactly in the shift' => [$day, [['09:00', '09:35']], [], [], ['09:00']],
            'a service one minute too long for the shift' => [$day, [['09:00', '09:34']], [], [], []],
            'a shift that starts off the grid' => [$day, [['09:10', '11:00']], [], [], ['09:15', '09:30', '09:45', '10:00', '10:15']],
            'two shifts that touch are one' => [$day, [['09:00', '10:00'], ['10:00', '11:00']], [], [], $all],
            'two shifts with a gap too short' => [$day, [['09:00', '10:00'], ['10:30', '11:00']], [], [], ['09:00', '09:15']],
            'two open ranges that touch are one' => [[['09:00', '10:00'], ['10:00', '11:00']], $day, [], [], $all],
            'a break in the middle' => [$day, $day, [['10:00', '10:30']], [], ['09:00', '09:15']],
            'a break that starts where a space ends' => [$day, $day, [['09:35', '10:00']], [], ['09:00', '10:00', '10:15']],
            'a reservation in the middle' => [$day, $day, [], [['10:00', '10:20']], ['09:00', '09:15']],
            'a reservation at the start' => [$day, $day, [], [['09:30', '09:50']], ['10:00', '10:15']],
            'a reservation that ends where a space starts' => [$day, $day, [], [['08:30', '09:30']], ['09:30', '09:45', '10:00', '10:15']],
            'a reservation whose cleaning ends where a space starts' => [$day, $day, [], [['09:00', '09:15']], ['09:15', '09:30', '09:45', '10:00', '10:15']],
        ];
    }

    /**
     * The anticipation and the window of DI-03 with their edges: the limits are inclusive.
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('moments')]
    public function test_spaces_respect_the_notice_and_the_window(string $now, array $expected): void
    {
        $slots = $this->calculate([['09:00', '11:00']], [['09:00', '11:00']], [], 30, now: $now);

        $this->assertSame($expected, $this->starts($slots));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function moments(): array
    {
        $all = ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15'];

        return [
            'exactly 60 minutes ahead' => ['2030-03-05 08:00', $all],
            'one minute under the notice' => ['2030-03-05 08:01', ['09:15', '09:30', '09:45', '10:00', '10:15']],
            'one minute over the notice' => ['2030-03-05 07:59', $all],
            'in the middle of the notice' => ['2030-03-05 08:45', ['09:45', '10:00', '10:15']],
            'one minute inside the window' => ['2030-02-03 10:01', ['09:00', '09:15', '09:30', '09:45', '10:00']],
            'the day has passed' => ['2030-03-06 08:00', []],
            'exactly 30 days ahead' => ['2030-02-03 10:00', ['09:00', '09:15', '09:30', '09:45', '10:00']],
            'one minute beyond the window' => ['2030-02-03 09:59', ['09:00', '09:15', '09:30', '09:45']],
            'beyond the window' => ['2030-01-01 08:00', []],
        ];
    }

    /** Spaces are instants: 9:00 in Costa Rica is 15:00 UTC, and a clock in another zone gives the same answer. */
    public function test_wall_clock_times_are_costa_rica_times(): void
    {
        $now = new DateTimeImmutable('2030-03-05 12:00', new DateTimeZone('UTC')); // 06:00 in Costa Rica
        $slots = $this->calculate([['09:00', '11:00']], [['09:00', '11:00']], [], 30, now: $now);

        $this->assertSame(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15'], $this->starts($slots));
        $this->assertSame('15:00', $slots[0]->start->setTimezone(new DateTimeZone('UTC'))->format('H:i'));
        $this->assertSame('09:30', $slots[0]->end->format('H:i'));
    }

    /** The last space of the day stays inside the day and the one after midnight starts at 00:00. */
    public function test_spaces_near_midnight(): void
    {
        $late = $this->calculate([['22:00', '23:59']], [['22:00', '23:59']], [], 30);

        $starts = $this->starts($late);

        $this->assertSame('22:00', $starts[0]);
        $this->assertSame('23:15', end($starts));
        $this->assertSame('2030-03-05', array_last($late)?->end->format('Y-m-d'));
    }

    /** A reservation that crosses the midnight blocks the first spaces of the next day, and only those. */
    public function test_a_reservation_from_the_day_before_blocks_the_start_of_the_next_day(): void
    {
        $slots = $this->calculate(
            [['00:00', '02:00']],
            [['00:00', '02:00']],
            [],
            30,
            blocked: [TimeInterval::between($this->at('2030-03-05 23:30'), $this->at('2030-03-06 00:10'))],
            date: '2030-03-06',
        );

        $this->assertSame('00:15', $this->starts($slots)[0]);
    }

    /** The notice crosses the midnight: from 23:30 the earliest space of the next day is 00:30. */
    public function test_the_notice_crosses_the_midnight(): void
    {
        $slots = $this->calculate([['00:00', '02:00']], [['00:00', '02:00']], [], 30, now: '2030-03-05 23:30', date: '2030-03-06');

        $this->assertSame('00:30', $this->starts($slots)[0]);
    }

    /**
     * The notice is counted on the Costa Rica clock whatever the zone of the one that asks: 05:00 UTC is midnight in Costa
     * Rica, so the earliest space of the next day is 1:00 and the edge is inclusive.
     */
    #[DataProvider('utcMoments')]
    public function test_the_notice_at_the_midnight_of_costa_rica(string $utc, string $first): void
    {
        $now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $slots = $this->calculate([['00:00', '03:00']], [['00:00', '03:00']], [], 30, now: $now, date: '2030-03-06');

        $this->assertSame($first, $this->starts($slots)[0]);
    }

    /** @return array<string, array{string, string}> */
    public static function utcMoments(): array
    {
        return [
            '23:00 in Costa Rica, 60 minutes before midnight' => ['2030-03-06 05:00', '00:00'],
            'one minute after that' => ['2030-03-06 05:01', '00:15'],
            'midnight in Costa Rica' => ['2030-03-06 06:00', '01:00'],
        ];
    }

    /** The cleaning counts as part of the space: it fits when it ends exactly where a reservation starts, not a minute later (DI-01). */
    public function test_the_cleaning_of_the_space_counts_against_the_next_reservation(): void
    {
        $reservation = [TimeInterval::between($this->at(self::DAY.' 10:00'), $this->at(self::DAY.' 10:20'))];

        $exact = $this->calculate([['09:00', '11:00']], [['09:00', '11:00']], [], 40, blocked: $reservation);
        $over = $this->calculate([['09:00', '11:00']], [['09:00', '11:00']], [], 41, blocked: $reservation);

        $this->assertSame(['09:00', '09:15'], $this->starts($exact)); // 09:15 + 40 + 5 ends at 10:00
        $this->assertSame(['09:00'], $this->starts($over)); // 09:15 + 41 + 5 ends at 10:01
    }

    /** The closest spaces to the requested start come first, the earlier one wins a tie, and they are listed by start. */
    public function test_nearest_picks_the_closest_starts(): void
    {
        $slots = array_map(fn (string $time): Slot => $this->slot($time), ['09:00', '09:30', '10:15', '11:00', '12:00']);

        $nearest = new SlotCalculator()->nearest($slots, $this->at('2030-03-05 10:00'), 3);

        $this->assertSame(['09:00', '09:30', '10:15'], $this->starts($nearest));
    }

    /** With fewer spaces than the limit, all of them come back. */
    public function test_nearest_returns_all_when_there_are_fewer_than_the_limit(): void
    {
        $nearest = new SlotCalculator()->nearest([$this->slot('11:00')], $this->at('2030-03-05 10:00'), 3);

        $this->assertSame(['11:00'], $this->starts($nearest));
    }

    private function slot(string $time): Slot
    {
        $start = $this->at(self::DAY.' '.$time);

        return new Slot(new CollaboratorId(self::MAURICIO), $start, $start->modify('+30 minutes'));
    }

    /**
     * @param  list<array{string, string}>  $open
     * @param  list<array{string, string}>  $shifts
     * @param  list<array{string, string}>  $breaks
     * @param  list<TimeInterval>  $blocked
     * @return list<Slot>
     */
    private function calculate(array $open, array $shifts, array $breaks, int $service, array $blocked = [], string|DateTimeImmutable $now = self::EARLY, string $date = self::DAY): array
    {
        $agenda = new DayAgenda(
            new CollaboratorId(self::MAURICIO),
            CalendarDate::of($date),
            $this->ranges($open),
            $this->ranges($shifts),
            $this->ranges($breaks),
            $blocked,
        );

        return new SlotCalculator()->forAgenda($agenda, Duration::ofMinutes($service), $now instanceof DateTimeImmutable ? $now : $this->at($now));
    }

    /**
     * @param  list<array{string, string}>  $ranges
     * @return list<TimeRange>
     */
    private function ranges(array $ranges): array
    {
        return array_map(fn (array $range): TimeRange => TimeRange::between(new TimeOfDay($range[0]), new TimeOfDay($range[1])), $ranges);
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<string>
     */
    private function starts(array $slots): array
    {
        return array_map(fn (Slot $slot): string => $slot->start->format('H:i'), $slots);
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone(CalendarDate::ZONE));
    }
}
