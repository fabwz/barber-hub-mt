<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use Src\Catalog\Absences\Domain\Exceptions\InvalidAbsenceException;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/**
 * The time an absence takes, as the owner writes it: a range of dates and, optionally, the hours of the first and the last day.
 * Every wall-clock time is a time of Costa Rica (HO-03); the only place where it becomes an instant is here.
 */
final readonly class AbsenceRange
{
    public const string ZONE = 'America/Costa_Rica';

    private function __construct(public TimeInterval $interval) {}

    /**
     * Without hours the absence takes whole days, from the midnight that starts the first one to the midnight that ends the
     * last one. With hours it starts at the first hour of the first day and ends at the second hour of the last day: one
     * continuous interval. The hours come together or not at all.
     *
     * @throws InvalidAbsenceException when a date or an hour is not real, only one hour comes or the range ends before it starts
     */
    public static function fromLocal(string $startDate, string $endDate, ?string $startTime, ?string $endTime): self
    {
        if (($startTime === null) !== ($endTime === null)) {
            throw InvalidAbsenceException::hoursTogether($startTime === null ? 'hora_desde' : 'hora_hasta');
        }

        $first = self::date($startDate, 'fecha_desde');
        $last = self::date($endDate, 'fecha_hasta');

        if ($startTime === null || $endTime === null) {
            if ($last < $first) {
                throw InvalidAbsenceException::rangeInverted('fecha_hasta');
            }

            return new self(TimeInterval::between($first, $last->modify('+1 day')));
        }

        $start = self::moment($first, $startTime, 'hora_desde');
        $end = self::moment($last, $endTime, 'hora_hasta');

        if ($start >= $end) {
            throw InvalidAbsenceException::rangeInverted($startDate === $endDate ? 'hora_hasta' : 'fecha_hasta');
        }

        return new self(TimeInterval::between($start, $end));
    }

    /** The midnight that starts a real calendar date written as YYYY-MM-DD. */
    private static function date(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::ZONE));

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw InvalidAbsenceException::dateInvalid($field);
        }

        return $date;
    }

    /** A 24-hour time written as HH:MM on a day. */
    private static function moment(DateTimeImmutable $day, string $time, string $field): DateTimeImmutable
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw InvalidAbsenceException::timeInvalid($field);
        }

        return $day->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
    }
}
