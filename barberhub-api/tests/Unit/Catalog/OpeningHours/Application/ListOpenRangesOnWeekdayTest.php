<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\OpeningHours\Application;

use PHPUnit\Framework\TestCase;
use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Catalog\OpeningHours\Application\UseCases\AddTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnWeekday;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\Catalog\InMemoryTimeSlotRepository;

/** What the schedule of a collaborator reads: the ranges open on a day of the week (HO-02). */
final class ListOpenRangesOnWeekdayTest extends TestCase
{
    /** The ranges of a day come ordered by opening time, and a day without slots is closed. */
    public function test_a_weekday_lists_its_ranges_in_order(): void
    {
        $slots = new InMemoryTimeSlotRepository;
        $add = new AddTimeSlot($slots);
        $add->execute(new AddTimeSlotCommand(1, '13:00', '18:00'));
        $add->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $listOpenRanges = new ListOpenRangesOnWeekday($slots);

        $monday = array_map(fn (TimeRange $range): string => "{$range->start->value}-{$range->end->value}", $listOpenRanges->execute(new DayOfWeek(1)));

        $this->assertSame(['09:00-12:00', '13:00-18:00'], $monday);
        $this->assertSame([], $listOpenRanges->execute(new DayOfWeek(7)));
    }
}
