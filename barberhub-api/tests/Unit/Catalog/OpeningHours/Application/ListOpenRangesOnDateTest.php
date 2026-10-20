<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\OpeningHours\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Closures\Application\DTOs\RegisterClosureCommand;
use Src\Catalog\Closures\Application\UseCases\RegisterClosure;
use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Catalog\OpeningHours\Application\UseCases\AddTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnDate;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\Catalog\InMemoryClosedDates;
use Tests\Support\Catalog\InMemoryClosureRepository;
use Tests\Support\Catalog\InMemoryTimeSlotRepository;

/** What the availability of #53 reads: the ranges open on a date (RF-04, HO-01, HO-03). */
final class ListOpenRangesOnDateTest extends TestCase
{
    private InMemoryClosureRepository $closures;

    private ListOpenRangesOnDate $listOpenRanges;

    private DateTimeImmutable $now;

    /** A barbershop that opens Monday to Saturday in the morning and in the afternoon, and is closed on Sunday. */
    protected function setUp(): void
    {
        $slots = new InMemoryTimeSlotRepository;
        $this->closures = new InMemoryClosureRepository;
        $this->listOpenRanges = new ListOpenRangesOnDate($slots, new InMemoryClosedDates($this->closures));
        $this->now = $this->at('2026-10-20 08:00');

        $add = new AddTimeSlot($slots);

        foreach (range(1, 6) as $day) {
            $add->execute(new AddTimeSlotCommand($day, '13:00', '18:00'));
            $add->execute(new AddTimeSlotCommand($day, '09:00', '12:00'));
        }
    }

    /** A date lists the ranges of its day of the week, ordered by opening time. */
    public function test_a_date_lists_the_ranges_of_its_weekday(): void
    {
        $ranges = $this->listOpenRanges->execute($this->at('2026-10-21 00:00'));

        $this->assertSame(['09:00-12:00', '13:00-18:00'], $this->describe($ranges));
    }

    /** The hour of the moment does not matter, only its date: the same day gives the same ranges. */
    public function test_the_hour_of_the_moment_does_not_change_the_answer(): void
    {
        $this->assertSame(
            $this->describe($this->listOpenRanges->execute($this->at('2026-10-21 00:00'))),
            $this->describe($this->listOpenRanges->execute($this->at('2026-10-21 23:59'))),
        );
    }

    /** A Sunday the barbershop does not open on has no ranges (RF-04). */
    public function test_a_closed_weekday_has_no_ranges(): void
    {
        $this->assertSame([], $this->listOpenRanges->execute($this->at('2026-10-18 10:00')));
    }

    /** A holiday marked as closed has no ranges although its weekday does (RF-04). */
    public function test_a_closed_date_has_no_ranges(): void
    {
        $this->assertNotSame([], $this->listOpenRanges->execute($this->at('2026-12-25 10:00')));

        new RegisterClosure($this->closures)->execute(new RegisterClosureCommand('2026-12-25', 'Navidad'), $this->now);

        $this->assertSame([], $this->listOpenRanges->execute($this->at('2026-12-25 10:00')));
        $this->assertNotSame([], $this->listOpenRanges->execute($this->at('2026-12-24 10:00')));
    }

    /** The date is read in the time zone it comes in: 20:00 of a Sunday in Costa Rica is still Sunday. */
    public function test_the_date_is_read_in_costa_rica(): void
    {
        $sundayNight = $this->at('2026-10-18 20:00');

        $this->assertSame([], $this->listOpenRanges->execute($sundayNight));
        $this->assertNotSame([], $this->listOpenRanges->execute($sundayNight->setTimezone(new DateTimeZone('UTC'))));
    }

    /**
     * Describe the ranges as "start-end".
     *
     * @param  list<TimeRange>  $ranges
     * @return list<string>
     */
    private function describe(array $ranges): array
    {
        return array_map(fn (TimeRange $range): string => "{$range->start->value}-{$range->end->value}", $ranges);
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
