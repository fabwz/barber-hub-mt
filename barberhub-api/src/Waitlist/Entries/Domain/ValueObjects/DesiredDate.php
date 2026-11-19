<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Waitlist\Entries\Domain\Exceptions\DesiredDateInPastException;

/** The day a customer wants, a calendar date of the barbershop in Costa Rica. */
final readonly class DesiredDate
{
    private const string ZONE = 'America/Costa_Rica';

    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private function __construct(public string $value) {}

    /** Read a stored day: a real date written as YYYY-MM-DD. */
    public static function of(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidValueException('waitlist.date_invalid');
        }

        return new self($value);
    }

    /**
     * Take the day a customer asks for now: a real date that is today or later in Costa Rica, as a row for a day that has passed
     * can never get an offer.
     *
     * @throws DesiredDateInPastException
     */
    public static function upcoming(string $value, DateTimeImmutable $now): self
    {
        $date = self::of($value);

        if ($date->value < $now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d')) {
            throw DesiredDateInPastException::create();
        }

        return $date;
    }
}
