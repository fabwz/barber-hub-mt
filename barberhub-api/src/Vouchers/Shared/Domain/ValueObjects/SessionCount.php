<?php

declare(strict_types=1);

namespace Src\Vouchers\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** The number of sessions a voucher gives: a whole number of at least 1 (BO-01). */
final readonly class SessionCount
{
    /** Largest value the smallint column holds. */
    public const int MAX = 32767;

    private function __construct(public int $value) {}

    /** Build the count; zero, negative or beyond the capacity of the column is rejected. */
    public static function of(int $value): self
    {
        if ($value < 1 || $value > self::MAX) {
            throw new InvalidValueException('vouchers.sessions_count_invalid');
        }

        return new self($value);
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
