<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\ValueObjects;

/** A collaborator who is free for a space, with what DI-04 compares: the reservations of the day and the seniority. */
final readonly class AssignmentCandidate
{
    /**
     * @param  int  $reservationsThatDay  Reservations of the collaborator on the day of the space that DI-04 counts.
     * @param  int  $seniorityRank  Position by registration date, 0 for the oldest.
     */
    public function __construct(
        public CollaboratorId $collaboratorId,
        public int $reservationsThatDay,
        public int $seniorityRank,
    ) {}
}
