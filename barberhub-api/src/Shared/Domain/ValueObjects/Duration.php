<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class Duration
{
    private function __construct(public int $minutes) {}

    /** Build a duration of one or more whole minutes. */
    public static function ofMinutes(int $minutes): self
    {
        if ($minutes <= 0) {
            throw new InvalidValueException('shared.duration_not_positive');
        }

        return new self($minutes);
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }
}
