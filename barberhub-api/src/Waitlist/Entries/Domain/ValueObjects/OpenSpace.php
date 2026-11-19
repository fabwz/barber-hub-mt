<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\TimeInterval;

/** A free space the customer can book instead of waiting: the collaborator and the interval of the service. */
final readonly class OpenSpace
{
    /** Hold the collaborator and the interval of the service, without the cleaning. */
    public function __construct(
        public CollaboratorId $collaboratorId,
        public TimeInterval $interval,
    ) {}
}
