<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class CalendarDateTest extends TestCase
{
    /** Only a real date written as YYYY-MM-DD is accepted. */
    #[DataProvider('invalidDates')]
    public function test_rejects_a_date_that_is_not_a_real_one(string $value): void
    {
        try {
            CalendarDate::of($value);
            $this->fail("The date {$value} was accepted.");
        } catch (InvalidValueException $exception) {
            $this->assertSame('availability.date_invalid', $exception->translationKey());
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidDates(): array
    {
        return [
            'empty' => [''],
            'words' => ['hoy'],
            'no zero padding' => ['2030-3-5'],
            'a day that does not exist' => ['2030-02-30'],
            'a month that does not exist' => ['2030-13-01'],
            'with a time' => ['2030-03-05 10:00'],
        ];
    }

    /** The day of the week is that of the date in Costa Rica. */
    public function test_knows_its_day_of_the_week(): void
    {
        $this->assertSame(2, CalendarDate::of('2030-03-05')->dayOfWeek()->value);
        $this->assertSame(7, CalendarDate::of('2030-03-03')->dayOfWeek()->value);
        $this->assertSame(1, CalendarDate::of('2030-03-04')->dayOfWeek()->value);
    }

    /** A moment belongs to the day it has in Costa Rica (UTC-6), whatever zone it is written in. */
    #[DataProvider('moments')]
    public function test_takes_the_costa_rica_day_of_a_moment(string $moment, string $zone, string $expected): void
    {
        $this->assertSame($expected, CalendarDate::fromMoment(new DateTimeImmutable($moment, new DateTimeZone($zone)))->value);
    }

    /** @return array<string, array{string, string, string}> */
    public static function moments(): array
    {
        return [
            'the first instant of the day' => ['2030-03-05 06:00', 'UTC', '2030-03-05'],
            'the last instant of the day before' => ['2030-03-05 05:59:59', 'UTC', '2030-03-04'],
            'late evening in UTC is the same day' => ['2030-03-06 03:00', 'UTC', '2030-03-05'],
            'local midnight' => ['2030-03-05 00:00', 'America/Costa_Rica', '2030-03-05'],
            'local last minute' => ['2030-03-05 23:59', 'America/Costa_Rica', '2030-03-05'],
        ];
    }

    /** Minutes after the midnight become Costa Rica instants, and the day ends at the next midnight. */
    public function test_converts_minutes_into_instants(): void
    {
        $date = CalendarDate::of('2030-03-05');

        $this->assertSame('2030-03-05T06:00:00+00:00', $date->at(0)->setTimezone(new DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2030-03-05T15:00:00+00:00', $date->at(540)->setTimezone(new DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2030-03-06 00:00', $date->at(1440)->format('Y-m-d H:i'));
        $this->assertSame($date->at(0)->getTimestamp(), $date->window()->start->getTimestamp());
        $this->assertSame($date->at(1440)->getTimestamp(), $date->window()->end->getTimestamp());
    }
}
