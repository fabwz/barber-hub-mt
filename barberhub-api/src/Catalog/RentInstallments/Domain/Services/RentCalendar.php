<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar of the rent (PL-05, HO-03). An installment falls due on the day of the month the barbershop was registered; in a month
 * that lacks that day, on its last day. Every date is a calendar day of Costa Rica, as a DateTimeImmutable at midnight of that zone; the
 * instant comes in as a parameter and the clock is never read here (RD-04).
 */
final readonly class RentCalendar
{
    public const string ZONE = 'America/Costa_Rica';

    /** @param  int  $anniversaryDay  Day of the month, 1 to 31, on which the barbershop was registered. */
    private function __construct(private int $anniversaryDay) {}

    /** The calendar of a barbershop registered at this instant. */
    public static function registeredAt(DateTimeImmutable $registeredAt): self
    {
        return new self((int) self::dayOf($registeredAt)->format('j'));
    }

    /** The calendar day of Costa Rica that contains the instant, at midnight. */
    public static function dayOf(DateTimeImmutable $instant): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);

        return new DateTimeImmutable($instant->setTimezone($zone)->format('Y-m-d'), $zone);
    }

    /** The day the installment of a month falls due: the anniversary day, or the last day of a shorter month. */
    public function dueOn(int $year, int $month): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $zone))->format('t');

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min($this->anniversaryDay, $lastDay)), $zone);
    }

    /** The due date of the first installment: the first one that is not before today (a barbershop registered today owes its first one today). */
    public function firstDueOn(DateTimeImmutable $now): DateTimeImmutable
    {
        $today = self::dayOf($now);
        $thisMonth = $this->dueOn((int) $today->format('Y'), (int) $today->format('n'));

        return $thisMonth >= $today ? $thisMonth : $this->following($thisMonth);
    }

    /** The due date of the month after the one of the given due date. */
    public function following(DateTimeImmutable $dueOn): DateTimeImmutable
    {
        $first = $dueOn->modify('first day of next month');

        return $this->dueOn((int) $first->format('Y'), (int) $first->format('n'));
    }

    /**
     * The due dates still to create. With no installment yet, only the first. Otherwise the month that follows the latest one is created
     * once the latest one is over (its due day is before today), so there is never more than one installment that has not fallen due;
     * after an outage it catches up month by month. Running it again the same day finds nothing to add.
     *
     * @return list<DateTimeImmutable>
     */
    public function missingDueDates(?DateTimeImmutable $latest, DateTimeImmutable $now): array
    {
        if ($latest === null) {
            return [$this->firstDueOn($now)];
        }

        $today = self::dayOf($now);
        $missing = [];

        while ($latest < $today) {
            $latest = $this->following($latest);
            $missing[] = $latest;
        }

        return $missing;
    }
}
