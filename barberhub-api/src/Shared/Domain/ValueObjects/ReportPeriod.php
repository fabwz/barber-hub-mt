<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/**
 * A range of calendar days of Costa Rica, both ends included, as the half-open interval of instants that covers it: from 00:00 of
 * the first day up to, and not including, 00:00 of the day after the last one (RF-25). Every context that adds up figures for a
 * report reads the same instants, so the figures of the contexts are about the same days.
 */
final readonly class ReportPeriod
{
    /** The time zone of the business (RNF-22); it has no daylight saving time, so a day is always 24 hours. */
    public const string TIMEZONE = 'America/Costa_Rica';

    /** The longest range a report accepts, in days. */
    public const int MAX_DAYS = 366;

    private const string DATE_FORMAT = 'Y-m-d';

    private function __construct(
        public string $firstDate,
        public string $lastDate,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {}

    /** Build the period from the first and the last day, written `YYYY-MM-DD`, the first not after the last and at most MAX_DAYS days long. */
    public static function between(string $firstDate, string $lastDate): self
    {
        $first = self::parse($firstDate);
        $last = self::parse($lastDate);

        if ($first > $last) {
            throw new InvalidValueException('shared.report_period_reversed');
        }

        $until = $last->modify('+1 day');
        $days = (int) $first->diff($until)->days;

        if ($days > self::MAX_DAYS) {
            throw new InvalidValueException('shared.report_period_too_long', ['max' => self::MAX_DAYS]);
        }

        return new self($firstDate, $lastDate, $first, $until);
    }

    /** Read a day at 00:00 of Costa Rica; text that is not a real day in that format is refused. */
    private static function parse(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, new DateTimeZone(self::TIMEZONE));

        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $date) {
            throw new InvalidValueException('shared.report_period_invalid_date');
        }

        return $parsed;
    }
}
