<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Collaborators\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Collaborators\Domain\Exceptions\BreakOutsideShiftException;
use Src\Catalog\Collaborators\Domain\Exceptions\BreaksOverlapException;
use Src\Catalog\Collaborators\Domain\Exceptions\ScheduleEntryException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftsOverlapException;
use Src\Catalog\Collaborators\Domain\Services\RangesCover;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final class WorkScheduleTest extends TestCase
{
    /** A schedule without entries is valid: the collaborator works no day. */
    public function test_an_empty_schedule_is_valid(): void
    {
        $schedule = WorkSchedule::empty();

        $this->assertSame([], $schedule->shifts);
        $this->assertSame([], $schedule->breaks);
    }

    /** Shifts that touch at an end, or fall on different days, do not overlap. */
    public function test_touching_shifts_and_shifts_of_other_days_are_accepted(): void
    {
        $schedule = WorkSchedule::of(
            [$this->shift(1, '09:00', '12:00'), $this->shift(1, '12:00', '15:00'), $this->shift(2, '09:00', '12:00')],
            [],
        );

        $this->assertCount(3, $schedule->shifts);
    }

    /** Two shifts of one day that share a minute are refused, pointing at the later one (HO-02). */
    public function test_overlapping_shifts_are_refused_at_the_later_one(): void
    {
        $this->assertRejected(
            fn (): WorkSchedule => WorkSchedule::of([$this->shift(1, '09:00', '12:00'), $this->shift(2, '09:00', '12:00'), $this->shift(1, '11:00', '14:00')], []),
            ShiftsOverlapException::class,
            ScheduleSection::Shifts,
            2,
        );
    }

    /** Two breaks of one day that share a minute are refused, pointing at the later one. */
    public function test_overlapping_breaks_are_refused_at_the_later_one(): void
    {
        $this->assertRejected(
            fn (): WorkSchedule => WorkSchedule::of(
                [$this->shift(1, '09:00', '18:00')],
                [$this->break(1, '12:00', '13:00'), $this->break(1, '12:30', '13:30')],
            ),
            BreaksOverlapException::class,
            ScheduleSection::Breaks,
            1,
        );
    }

    /** A break inside a shift is accepted, even when it touches an end of the shift. */
    public function test_a_break_inside_a_shift_is_accepted(): void
    {
        $schedule = WorkSchedule::of(
            [$this->shift(1, '09:00', '18:00')],
            [$this->break(1, '09:00', '09:30'), $this->break(1, '12:00', '13:00')],
        );

        $this->assertCount(2, $schedule->breaks);
    }

    /** A break that leaves the shift, falls on a day without shifts or spans a gap is refused (HO-02). */
    #[DataProvider('breaksOutsideTheShifts')]
    public function test_a_break_outside_the_shifts_is_refused(int $day, string $start, string $end): void
    {
        $this->assertRejected(
            fn (): WorkSchedule => WorkSchedule::of(
                [$this->shift(1, '09:00', '12:00'), $this->shift(1, '14:00', '18:00')],
                [$this->break($day, $start, $end)],
            ),
            BreakOutsideShiftException::class,
            ScheduleSection::Breaks,
            0,
        );
    }

    /**
     * Breaks that do not fall inside the shifts of the day.
     *
     * @return array<string, array{int, string, string}>
     */
    public static function breaksOutsideTheShifts(): array
    {
        return [
            'starts before the shift' => [1, '08:30', '09:30'],
            'ends after the shift' => [1, '17:30', '18:30'],
            'spans the gap between shifts' => [1, '11:30', '14:30'],
            'day without shifts' => [2, '10:00', '11:00'],
        ];
    }

    /** A break may span two shifts that touch, because the collaborator is there the whole time. */
    public function test_a_break_may_span_two_touching_shifts(): void
    {
        $schedule = WorkSchedule::of(
            [$this->shift(1, '09:00', '12:00'), $this->shift(1, '12:00', '15:00')],
            [$this->break(1, '11:30', '12:30')],
        );

        $this->assertCount(1, $schedule->breaks);
    }

    /**
     * A range is covered by one range, by touching ranges, and not by ranges with a gap or too short.
     *
     * @param  list<array{string, string}>  $ranges
     */
    #[DataProvider('coverages')]
    public function test_ranges_cover(array $ranges, string $start, string $end, bool $expected): void
    {
        $this->assertSame($expected, RangesCover::covers(
            array_map(fn (array $range): TimeRange => $this->range(...$range), $ranges),
            $this->range($start, $end),
        ));
    }

    /**
     * Ranges, a target and whether the union of the ranges covers it.
     *
     * @return array<string, array{list<array{string, string}>, string, string, bool}>
     */
    public static function coverages(): array
    {
        return [
            'exactly one range' => [[['09:00', '12:00']], '09:00', '12:00', true],
            'inside one range' => [[['09:00', '12:00']], '10:00', '11:00', true],
            'starts before the opening' => [[['09:00', '12:00']], '08:30', '11:00', false],
            'ends after the closing' => [[['09:00', '12:00']], '10:00', '12:30', false],
            'touching ranges in any order' => [[['12:00', '15:00'], ['09:00', '12:00']], '10:00', '14:00', true],
            'ranges with a gap' => [[['09:00', '12:00'], ['13:00', '18:00']], '11:00', '14:00', false],
            'no ranges' => [[], '09:00', '10:00', false],
        ];
    }

    /**
     * Check the exception, the list it points at and the position of the rejected entry.
     *
     * @param  class-string<ScheduleEntryException>  $exception
     */
    private function assertRejected(callable $build, string $exception, ScheduleSection $section, int $index): void
    {
        try {
            $build();
            $this->fail('The schedule was accepted.');
        } catch (ScheduleEntryException $caught) {
            $this->assertInstanceOf($exception, $caught);
            $this->assertSame($section, $caught->section());
            $this->assertSame($index, $caught->index());
        }
    }

    /** Build a shift. */
    private function shift(int $day, string $start, string $end): Shift
    {
        return new Shift(new DayOfWeek($day), $this->range($start, $end));
    }

    /** Build a break. */
    private function break(int $day, string $start, string $end): BreakPeriod
    {
        return new BreakPeriod(new DayOfWeek($day), $this->range($start, $end));
    }

    /** Build a range of times. */
    private function range(string $start, string $end): TimeRange
    {
        return TimeRange::between(new TimeOfDay($start), new TimeOfDay($end));
    }
}
