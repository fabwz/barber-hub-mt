<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\ValueObjects;

use DateTimeImmutable;

/** A free space of a collaborator: when the service starts and when it ends, without the cleaning that follows. */
final readonly class Slot
{
    public function __construct(
        public CollaboratorId $collaboratorId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}
}
