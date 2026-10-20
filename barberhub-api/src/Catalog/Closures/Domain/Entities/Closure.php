<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Domain\Entities;

use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureId;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureReason;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class Closure
{
    /** Hold the full state of a closure. */
    private function __construct(
        public ClosureId $id,
        public ClosureDate $date,
        public ?ClosureReason $reason,
    ) {}

    /** Register a closure for today or a later date. */
    public static function create(ClosureId $id, ClosureDate $date, ?ClosureReason $reason, ClosureDate $today): self
    {
        if ($date->isBefore($today)) {
            throw new InvalidValueException('closures.date_in_past');
        }

        return new self($id, $date, $reason);
    }

    /** Rebuild a closure from its stored state, even if its date already passed. */
    public static function reconstitute(ClosureId $id, ClosureDate $date, ?ClosureReason $reason): self
    {
        return new self($id, $date, $reason);
    }
}
