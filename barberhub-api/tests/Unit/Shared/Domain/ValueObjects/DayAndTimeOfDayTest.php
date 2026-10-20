<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;

final class DayAndTimeOfDayTest extends TestCase
{
    /** The days from Monday (1) to Sunday (7) are accepted. */
    public function test_accepts_the_seven_days(): void
    {
        foreach (range(1, 7) as $number) {
            $this->assertSame($number, new DayOfWeek($number)->value);
        }
    }

    /** A number out of 1 to 7 is refused with its limits. */
    public function test_rejects_a_day_out_of_range(): void
    {
        foreach ([0, 8, -1] as $number) {
            try {
                new DayOfWeek($number);
                $this->fail("The day {$number} was accepted.");
            } catch (InvalidValueException $exception) {
                $this->assertSame('shared.day_of_week_out_of_range', $exception->translationKey());
                $this->assertSame(['min' => 1, 'max' => 7], $exception->parameters());
            }
        }
    }

    /** The day comes from the moment in its own time zone: a Sunday in Costa Rica is a 7 even if it is Monday in UTC. */
    public function test_takes_the_day_in_the_time_zone_of_the_moment(): void
    {
        $sunday = new DateTimeImmutable('2026-10-18 20:00:00', new DateTimeZone('America/Costa_Rica'));

        $this->assertSame(7, DayOfWeek::fromMoment($sunday)->value);
        $this->assertSame(1, DayOfWeek::fromMoment($sunday->setTimezone(new DateTimeZone('UTC')))->value);
    }

    /**
     * Times written as HH:MM.
     *
     * @return array<string, array{string}>
     */
    public static function validTimes(): array
    {
        return ['midnight' => ['00:00'], 'morning' => ['09:05'], 'last minute' => ['23:59']];
    }

    /** A 24-hour time written as HH:MM is accepted. */
    #[DataProvider('validTimes')]
    public function test_accepts_a_time_as_hours_and_minutes(string $time): void
    {
        $this->assertSame($time, new TimeOfDay($time)->value);
    }

    /**
     * Texts that are not an HH:MM time.
     *
     * @return array<string, array{string}>
     */
    public static function invalidTimes(): array
    {
        return ['hour 24' => ['24:00'], 'minute 60' => ['09:60'], 'without zero' => ['9:00'], 'with seconds' => ['09:00:00'], 'text' => ['mediodía'], 'empty' => ['']];
    }

    /** Anything else is refused. */
    #[DataProvider('invalidTimes')]
    public function test_rejects_a_text_that_is_not_a_time(string $time): void
    {
        $this->expectException(InvalidValueException::class);

        new TimeOfDay($time);
    }

    /** The time of a moment drops the seconds and keeps the wall clock of its own zone. */
    public function test_takes_the_wall_clock_of_a_moment(): void
    {
        $moment = new DateTimeImmutable('2026-10-18 09:30:45', new DateTimeZone('America/Costa_Rica'));

        $this->assertSame('09:30', TimeOfDay::fromMoment($moment)->value);
    }

    /** Times compare in order. */
    public function test_compares_times(): void
    {
        $this->assertTrue(new TimeOfDay('09:00')->isBefore(new TimeOfDay('09:01')));
        $this->assertFalse(new TimeOfDay('09:00')->isBefore(new TimeOfDay('09:00')));
        $this->assertTrue(new TimeOfDay('09:00')->equals(new TimeOfDay('09:00')));
    }
}
