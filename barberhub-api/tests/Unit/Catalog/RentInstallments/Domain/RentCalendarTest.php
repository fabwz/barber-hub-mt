<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\RentInstallments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;

/** The due days of the rent (PL-05) and when the next installment is created, in the time of Costa Rica (HO-03). */
final class RentCalendarTest extends TestCase
{
    /** @return array<string, array{string, int, int, string}> */
    public static function dueDays(): array
    {
        return [
            'registered on the 10th, every month the 10th' => ['2026-11-10 09:00:00-06:00', 2026, 12, '2026-12-10'],
            'registered on 31 January, February has 28 days' => ['2026-01-31 09:00:00-06:00', 2026, 2, '2026-02-28'],
            'registered on 31 January, February of a leap year has 29' => ['2028-01-31 09:00:00-06:00', 2028, 2, '2028-02-29'],
            'registered on 31 January, March has 31' => ['2026-01-31 09:00:00-06:00', 2026, 3, '2026-03-31'],
            'registered on 31 January, April has 30' => ['2026-01-31 09:00:00-06:00', 2026, 4, '2026-04-30'],
            'registered on the 30th, February 28' => ['2026-01-30 09:00:00-06:00', 2026, 2, '2026-02-28'],
            'registered on the 30th, February 29 of a leap year' => ['2028-01-30 09:00:00-06:00', 2028, 2, '2028-02-29'],
            'registered on the 30th, April has the 30th' => ['2026-01-30 09:00:00-06:00', 2026, 4, '2026-04-30'],
            'registered on the 29th, February of a common year' => ['2026-03-29 09:00:00-06:00', 2026, 2, '2026-02-28'],
        ];
    }

    #[DataProvider('dueDays')]
    public function test_an_installment_falls_due_on_the_registration_day_or_the_last_day_of_the_month(string $registeredAt, int $year, int $month, string $expected): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable($registeredAt));

        $this->assertSame($expected, $calendar->dueOn($year, $month)->format('Y-m-d'));
    }

    /** A later month goes back to the real anniversary day: 31 January, February 28, March 31 (not 28). */
    public function test_the_shortened_month_does_not_move_the_anniversary(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-01-31 09:00:00-06:00'));

        $february = $calendar->following($calendar->dueOn(2026, 1));

        $this->assertSame('2026-02-28', $february->format('Y-m-d'));
        $this->assertSame('2026-03-31', $calendar->following($february)->format('Y-m-d'));
    }

    /** The registration day counts in Costa Rica: 02:00 UTC of the 11th is still the 10th at 20:00 there. */
    public function test_the_registration_day_is_the_day_in_costa_rica(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-11-11 02:00:00+00:00'));

        $this->assertSame('2026-11-10', $calendar->dueOn(2026, 11)->format('Y-m-d'));
    }

    /** With no installment the first one is the first anniversary that is not before today: today for a barbershop registered today. */
    public function test_the_first_installment_is_due_on_the_registration_day_and_later_for_an_old_barbershop(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-01-15 09:00:00-06:00'));

        $this->assertSame('2026-11-15', $calendar->firstDueOn(new DateTimeImmutable('2026-11-15 08:00:00-06:00'))->format('Y-m-d'));
        $this->assertSame('2026-11-15', $calendar->firstDueOn(new DateTimeImmutable('2026-11-14 08:00:00-06:00'))->format('Y-m-d'));
        $this->assertSame('2026-12-15', $calendar->firstDueOn(new DateTimeImmutable('2026-11-16 08:00:00-06:00'))->format('Y-m-d'));
    }

    /** The next month is created the day after the latest one falls due, never before. */
    public function test_the_next_installment_is_created_once_the_latest_is_over(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-11-10 09:00:00-06:00'));
        $latest = new DateTimeImmutable('2026-11-10', new \DateTimeZone('America/Costa_Rica'));

        $this->assertSame([], $calendar->missingDueDates($latest, new DateTimeImmutable('2026-11-10 12:00:00-06:00')));
        $this->assertSame(['2026-12-10'], $this->formatted($calendar->missingDueDates($latest, new DateTimeImmutable('2026-11-11 00:00:00-06:00'))));
    }

    /** Midnight of Costa Rica is the limit: 05:59 UTC is still the 10th there, 06:00 UTC is the 11th. */
    public function test_the_limit_is_midnight_in_costa_rica_not_in_utc(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-11-10 09:00:00-06:00'));
        $latest = new DateTimeImmutable('2026-11-10', new \DateTimeZone('America/Costa_Rica'));

        $this->assertSame([], $calendar->missingDueDates($latest, new DateTimeImmutable('2026-11-11 05:59:59+00:00')));
        $this->assertSame(['2026-12-10'], $this->formatted($calendar->missingDueDates($latest, new DateTimeImmutable('2026-11-11 06:00:00+00:00'))));
    }

    /** After an outage the missing months are all created, each with its own due day. */
    public function test_a_long_outage_is_caught_up_month_by_month(): void
    {
        $calendar = RentCalendar::registeredAt(new DateTimeImmutable('2026-01-31 09:00:00-06:00'));
        $latest = new DateTimeImmutable('2026-01-31', new \DateTimeZone('America/Costa_Rica'));

        $missing = $calendar->missingDueDates($latest, new DateTimeImmutable('2026-04-15 08:00:00-06:00'));

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], $this->formatted($missing));
    }

    /**
     * @param  list<DateTimeImmutable>  $dates
     * @return list<string>
     */
    private function formatted(array $dates): array
    {
        return array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}
