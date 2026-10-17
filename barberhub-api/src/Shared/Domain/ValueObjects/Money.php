<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class Money
{
    private function __construct(public int $colones) {}

    /** Build an amount of whole colones, never negative. */
    public static function of(int $colones): self
    {
        if ($colones < 0) {
            throw new InvalidValueException('shared.money_negative');
        }

        return new self($colones);
    }

    /** An amount of zero colones. */
    public static function zero(): self
    {
        return new self(0);
    }

    /** Add two amounts. */
    public function plus(self $other): self
    {
        return new self($this->colones + $other->colones);
    }

    /** Subtract an amount; the result cannot fall below zero. */
    public function minus(self $other): self
    {
        if ($other->colones > $this->colones) {
            throw new InvalidValueException('shared.money_below_zero');
        }

        return new self($this->colones - $other->colones);
    }

    /**
     * Take a percentage of the amount, rounded up to the next colon (PA-06).
     *
     * Integer arithmetic avoids floating-point error: ceil(a * p / 100).
     */
    public function percentage(Percentage $percentage): self
    {
        return new self(intdiv($this->colones * $percentage->value + 99, 100));
    }

    /** Whether this amount is strictly greater. */
    public function isGreaterThan(self $other): bool
    {
        return $this->colones > $other->colones;
    }

    /** Whether this amount is strictly smaller. */
    public function isLessThan(self $other): bool
    {
        return $this->colones < $other->colones;
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->colones === $other->colones;
    }
}
