<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\TimeInterval;

/** A free space offered in place of the one that was taken: the collaborator and the interval of the service (RE-02). */
final readonly class SlotAlternative
{
    /** Hold the collaborator and the interval of the service, without the cleaning. */
    public function __construct(
        public CollaboratorId $collaboratorId,
        public TimeInterval $interval,
    ) {}
}
