<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use DateTimeInterface;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class DayOfWeek
{
    public const int MONDAY = 1;

    public const int SUNDAY = 7;

    /** Accept an ISO 8601 day number, from Monday to Sunday. */
    public function __construct(public int $value)
    {
        if ($value < self::MONDAY || $value > self::SUNDAY) {
            throw new InvalidValueException('shared.day_of_week_out_of_range', [
                'min' => self::MONDAY,
                'max' => self::SUNDAY,
            ]);
        }
    }

    /** Take the day of the week a moment falls on, in its own time zone. */
    public static function fromMoment(DateTimeInterface $moment): self
    {
        return new self((int) $moment->format('N'));
    }

    /** Compare two days by value. */
    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
