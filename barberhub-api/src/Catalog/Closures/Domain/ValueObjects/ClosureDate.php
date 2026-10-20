<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class ClosureDate
{
    public const string FORMAT = 'Y-m-d';

    /** Accept only a real calendar date written as YYYY-MM-DD. */
    public function __construct(public string $value)
    {
        $date = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value);

        if ($date === false || $date->format(self::FORMAT) !== $value) {
            throw new InvalidValueException('closures.date_invalid');
        }
    }

    /** Take the calendar date of a moment in its own time zone. */
    public static function fromMoment(DateTimeInterface $moment): self
    {
        return new self($moment->format(self::FORMAT));
    }

    /** Tell whether this date comes before another one. */
    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }
}
