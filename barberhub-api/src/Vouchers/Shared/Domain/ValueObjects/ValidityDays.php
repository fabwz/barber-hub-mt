<?php

declare(strict_types=1);

namespace Src\Vouchers\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/** The days a voucher lasts from its activation: a whole number of at least 1 (BO-01, BO-07). */
final readonly class ValidityDays
{
    /** Largest value the smallint column holds. */
    public const int MAX = 32767;

    private function __construct(public int $value) {}

    /** Build the validity; zero, negative or beyond the capacity of the column is rejected. */
    public static function of(int $value): self
    {
        if ($value < 1 || $value > self::MAX) {
            throw new InvalidValueException('vouchers.validity_days_invalid');
        }

        return new self($value);
    }

    /** The instant a voucher activated at the given one stops being valid. */
    public function endingFrom(DateTimeImmutable $activatedAt): DateTimeImmutable
    {
        return $activatedAt->modify("+{$this->value} days");
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
