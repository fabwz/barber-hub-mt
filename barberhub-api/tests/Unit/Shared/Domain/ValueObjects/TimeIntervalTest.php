<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class TimeIntervalTest extends TestCase
{
    /** A valid interval keeps its two instants. */
    public function test_keeps_its_start_and_end(): void
    {
        $interval = $this->interval('10:00', '10:45');

        $this->assertEquals($this->at('10:00'), $interval->start);
        $this->assertEquals($this->at('10:45'), $interval->end);
    }

    /** The start must be strictly before the end. */
    public function test_rejects_a_start_equal_to_the_end(): void
    {
        try {
            $this->interval('10:00', '10:00');
            $this->fail('An empty interval was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.interval_start_not_before_end', $exception->translationKey());
        }
    }

    /** An inverted interval is rejected. */
    public function test_rejects_a_start_after_the_end(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->interval('11:00', '10:00');
    }

    /** Intervals that share some time overlap. */
    public function test_overlapping_intervals_overlap(): void
    {
        $this->assertTrue($this->interval('10:00', '11:00')->overlaps($this->interval('10:30', '11:30')));
        $this->assertTrue($this->interval('10:30', '11:30')->overlaps($this->interval('10:00', '11:00')));
    }

    /** An interval inside another, or equal to it, overlaps. */
    public function test_contained_and_identical_intervals_overlap(): void
    {
        $this->assertTrue($this->interval('10:00', '12:00')->overlaps($this->interval('10:30', '11:00')));
        $this->assertTrue($this->interval('10:30', '11:00')->overlaps($this->interval('10:00', '12:00')));
        $this->assertTrue($this->interval('10:00', '11:00')->overlaps($this->interval('10:00', '11:00')));
    }

    /** The end is excluded, so intervals that only touch do not overlap. */
    public function test_intervals_touching_at_an_end_do_not_overlap(): void
    {
        $this->assertFalse($this->interval('10:00', '11:00')->overlaps($this->interval('11:00', '12:00')));
        $this->assertFalse($this->interval('11:00', '12:00')->overlaps($this->interval('10:00', '11:00')));
    }

    /** Separated intervals do not overlap. */
    public function test_separated_intervals_do_not_overlap(): void
    {
        $this->assertFalse($this->interval('10:00', '11:00')->overlaps($this->interval('11:05', '12:00')));
    }

    /** The duration is measured in whole minutes. */
    public function test_computes_its_duration_in_minutes(): void
    {
        $this->assertSame(45, $this->interval('10:00', '10:45')->duration()->minutes);
        $this->assertSame(1, $this->interval('10:00', '10:01')->duration()->minutes);
    }

    /** An interval under one minute has no valid duration. */
    public function test_rejects_the_duration_of_an_interval_under_a_minute(): void
    {
        $interval = TimeInterval::between($this->at('10:00'), $this->at('10:00')->modify('+30 seconds'));

        $this->expectException(InvalidValueException::class);

        $interval->duration();
    }

    private function interval(string $start, string $end): TimeInterval
    {
        return TimeInterval::between($this->at($start), $this->at($end));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-03-14 {$time}:00", new DateTimeZone('America/Costa_Rica'));
    }
}
