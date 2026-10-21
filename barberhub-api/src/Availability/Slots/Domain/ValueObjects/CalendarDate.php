<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/**
 * A calendar day of the barbershop. It is the only place where wall-clock times of Costa Rica become instants (HO-03):
 * everything else in the module compares instants or minutes of this day.
 */
final readonly class CalendarDate
{
    public const string ZONE = 'America/Costa_Rica';

    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const int MINUTES_PER_DAY = 1440;

    private function __construct(public string $value) {}

    /** Accept a real date written as YYYY-MM-DD. */
    public static function of(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidValueException('availability.date_invalid');
        }

        return new self($value);
    }

    /** Take the day a moment falls on in Costa Rica, whatever the time zone the moment is written in. */
    public static function fromMoment(DateTimeInterface $moment): self
    {
        return new self(DateTimeImmutable::createFromInterface($moment)->setTimezone(self::zone())->format('Y-m-d'));
    }

    /** Day of the week of the date. */
    public function dayOfWeek(): DayOfWeek
    {
        return DayOfWeek::fromMoment($this->at(0));
    }

    /** The instant that is some minutes after the midnight that starts the day; 1440 is the midnight that ends it. */
    public function at(int $minutes): DateTimeImmutable
    {
        return new DateTimeImmutable("{$this->value} 00:00:00", self::zone())->modify("+{$minutes} minutes");
    }

    /** The whole day as an interval of instants, from its midnight to the next one. */
    public function window(): TimeInterval
    {
        return TimeInterval::between($this->at(0), $this->at(self::MINUTES_PER_DAY));
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }
}
