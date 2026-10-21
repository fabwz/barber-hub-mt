<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Application\DTOs\Availability;
use Src\Availability\Slots\Application\DTOs\DayAvailability;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Application\UseCases\SearchAvailability;
use Src\Availability\Slots\Domain\Exceptions\ServiceNotBookableException;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\Availability\InMemoryBarbershopHours;
use Tests\Support\Availability\InMemoryBlockedIntervals;
use Tests\Support\Availability\InMemoryServiceCatalog;
use Tests\Support\Availability\InMemoryStaffRoster;

/** The answer of the availability for a customer: the day asked for and, if it is empty, the next days with spaces (RF-10, DI-03). */
final class SearchAvailabilityTest extends TestCase
{
    private const string HAIRCUT = '0192f3a0-0000-7000-8000-0000000000a1';

    private const string MAURICIO = '0192f3a0-0000-7000-8000-000000000001';

    /** A Friday; the barbershop opens on Tuesdays unless a test says otherwise. */
    private const string NOW = '2030-03-01 08:00';

    private InMemoryServiceCatalog $services;

    private InMemoryStaffRoster $roster;

    private InMemoryBlockedIntervals $blocked;

    /** A day with spaces brings no next days and reads the service once. */
    public function test_a_day_with_spaces_has_no_next_days(): void
    {
        $answer = $this->search([2 => $this->open()])->execute(self::HAIRCUT, '2030-03-05', null, $this->now());

        $this->assertCount(1, $answer->day->staff);
        $this->assertSame([], $answer->nextDays);
        $this->assertSame('09:00', $answer->day->staff[0]->slots[0]->start->format('H:i'));
        $this->assertSame(1, $this->services->reads);
    }

    /** A Sunday brings the next 3 Tuesdays, and the search stops there: it does not read the rest of the window. */
    public function test_an_empty_day_brings_the_next_three_days_with_spaces_and_stops(): void
    {
        $answer = $this->search([2 => $this->open()])->execute(self::HAIRCUT, '2030-03-03', null, $this->now());

        $this->assertSame([], $answer->day->staff);
        $this->assertSame(['2030-03-05', '2030-03-12', '2030-03-19'], $this->dates($answer));
        $this->assertSame(3, $this->blocked->reads);
        $this->assertSame(1, $this->services->reads);
    }

    /** The days after the last one DI-03 allows are not searched: only 2 Tuesdays are left in the window. */
    public function test_the_search_stops_at_the_end_of_the_window(): void
    {
        $answer = $this->search([2 => $this->open()])->execute(self::HAIRCUT, '2030-03-20', null, $this->at('2030-03-01 08:00'));

        $this->assertSame(['2030-03-26'], $this->dates($answer));
    }

    /** With nothing free in 30 days the worst case reads each day of the window once: 31 days, and the service once. */
    public function test_the_worst_case_reads_each_day_of_the_window_once(): void
    {
        $this->blocked = new InMemoryBlockedIntervals([self::MAURICIO => [TimeInterval::between($this->at('2030-01-01 00:00'), $this->at('2031-01-01 00:00'))]]);
        $everyDay = array_fill_keys(range(1, 7), $this->open());

        $answer = $this->search($everyDay)->execute(self::HAIRCUT, '2030-03-01', null, $this->now());

        $this->assertSame([], $answer->day->staff);
        $this->assertSame([], $answer->nextDays);
        $this->assertSame(31, $this->blocked->reads);
        $this->assertSame(31, $this->roster->reads);
        $this->assertSame(1, $this->services->reads);
    }

    /** A date outside the window has no spaces and nothing is searched; the barbershop is not even asked about its days. */
    public function test_a_date_outside_the_window_is_empty(): void
    {
        foreach (['2030-02-28', '2030-04-02'] as $date) {
            $answer = $this->search([2 => $this->open()])->execute(self::HAIRCUT, $date, null, $this->now());

            $this->assertSame([], $answer->day->staff);
            $this->assertSame([], $answer->nextDays);
            $this->assertSame(0, $this->blocked->reads);
        }
    }

    /** The service that cannot be booked is refused before anything else is read. */
    public function test_an_unknown_service_is_refused(): void
    {
        $this->expectException(ServiceNotBookableException::class);

        $this->search([2 => $this->open()])->execute('0192f3a0-0000-7000-8000-0000000000ff', '2030-03-05', null, $this->now());
    }

    /** Nobody offering the service means no spaces and no search at all. */
    public function test_without_staff_there_is_nothing_to_search(): void
    {
        $this->roster = new InMemoryStaffRoster([]);

        $answer = $this->build([2 => $this->open()])->execute(self::HAIRCUT, '2030-03-03', null, $this->now());

        $this->assertSame([], $answer->nextDays);
        $this->assertSame(0, $this->blocked->reads);
    }

    /** @param array<int, list<TimeRange>> $weekly */
    private function search(array $weekly): SearchAvailability
    {
        $this->roster = new InMemoryStaffRoster([new StaffWorkday(self::MAURICIO, [$this->range('09:00', '19:00')], [])]);
        $this->blocked ??= new InMemoryBlockedIntervals;

        return $this->build($weekly);
    }

    /** @param array<int, list<TimeRange>> $weekly */
    private function build(array $weekly): SearchAvailability
    {
        $this->services = new InMemoryServiceCatalog([self::HAIRCUT => 30]);
        $this->blocked ??= new InMemoryBlockedIntervals;

        return new SearchAvailability(
            $this->services,
            $this->roster,
            new ListAvailableSlots($this->services, new InMemoryBarbershopHours($weekly), $this->roster, $this->blocked, new SlotCalculator),
        );
    }

    /** @return list<TimeRange> */
    private function open(): array
    {
        return [$this->range('09:00', '19:00')];
    }

    /** @return list<string> */
    private function dates(Availability $answer): array
    {
        return array_map(fn (DayAvailability $day): string => $day->date, $answer->nextDays);
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
