<?php

declare(strict_types=1);

namespace Src\Vouchers\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** The name of a voucher, trimmed, from 1 to 120 characters (BO-01). */
final readonly class VoucherName
{
    public const int MAX_LENGTH = 120;

    private function __construct(public string $value) {}

    /** Build the name; a blank or too long one is rejected. */
    public static function of(string $value): self
    {
        $name = trim($value);

        if ($name === '' || mb_strlen($name) > self::MAX_LENGTH) {
            throw new InvalidValueException('vouchers.name_invalid');
        }

        return new self($name);
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
