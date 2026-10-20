<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final class TimeRangeTest extends TestCase
{
    /** A valid range keeps its start and its end. */
    public function test_keeps_its_start_and_end(): void
    {
        $range = $this->range('09:00', '12:00');

        $this->assertSame('09:00', $range->start->value);
        $this->assertSame('12:00', $range->end->value);
    }

    /** The start must be strictly before the end: an empty or inverted range is refused. */
    public function test_rejects_a_start_that_is_not_before_the_end(): void
    {
        foreach ([['09:00', '09:00'], ['12:00', '09:00']] as [$start, $end]) {
            try {
                $this->range($start, $end);
                $this->fail("The range {$start}-{$end} was accepted.");
            } catch (InvalidValueException $exception) {
                $this->assertSame('shared.time_range_start_not_before_end', $exception->translationKey());
            }
        }
    }

    /** The start belongs to the range and the end does not. */
    public function test_contains_the_start_but_not_the_end(): void
    {
        $range = $this->range('09:00', '12:00');

        $this->assertFalse($range->contains(new TimeOfDay('08:59')));
        $this->assertTrue($range->contains(new TimeOfDay('09:00')));
        $this->assertTrue($range->contains(new TimeOfDay('11:59')));
        $this->assertFalse($range->contains(new TimeOfDay('12:00')));
    }

    /** Ranges that share some minute overlap, whichever contains the other. */
    public function test_ranges_that_share_a_minute_overlap(): void
    {
        $this->assertTrue($this->range('09:00', '12:00')->overlaps($this->range('11:59', '14:00')));
        $this->assertTrue($this->range('11:59', '14:00')->overlaps($this->range('09:00', '12:00')));
        $this->assertTrue($this->range('09:00', '18:00')->overlaps($this->range('10:00', '11:00')));
        $this->assertTrue($this->range('09:00', '12:00')->overlaps($this->range('09:00', '12:00')));
    }

    /** Ranges that only touch at an end do not overlap (HO-01). */
    public function test_contiguous_ranges_do_not_overlap(): void
    {
        $this->assertFalse($this->range('09:00', '12:00')->overlaps($this->range('12:00', '15:00')));
        $this->assertFalse($this->range('12:00', '15:00')->overlaps($this->range('09:00', '12:00')));
        $this->assertFalse($this->range('09:00', '12:00')->overlaps($this->range('13:00', '15:00')));
    }

    /** Two ranges are equal when both ends are. */
    public function test_equality_compares_both_ends(): void
    {
        $this->assertTrue($this->range('09:00', '12:00')->equals($this->range('09:00', '12:00')));
        $this->assertFalse($this->range('09:00', '12:00')->equals($this->range('09:00', '13:00')));
    }

    private function range(string $start, string $end): TimeRange
    {
        return TimeRange::between(new TimeOfDay($start), new TimeOfDay($end));
    }
}
