<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class Percentage
{
    private const int MIN = 0;

    private const int MAX = 100;

    private function __construct(public int $value) {}

    /** Build a whole percentage from 0 to 100. */
    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidValueException('shared.percentage_out_of_range');
        }

        return new self($value);
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
