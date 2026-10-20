<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use DateTimeInterface;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class TimeOfDay
{
    private const string PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    /** Accept a 24-hour time written as HH:MM, from 00:00 to 23:59. */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidValueException('shared.time_of_day_invalid');
        }
    }

    /** Take the wall-clock time of a moment, in its own time zone, without seconds. */
    public static function fromMoment(DateTimeInterface $moment): self
    {
        return new self($moment->format('H:i'));
    }

    /** Tell whether this time comes strictly before the other. */
    public function isBefore(self $other): bool
    {
        return strcmp($this->value, $other->value) < 0;
    }

    /** Compare two times by value. */
    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
