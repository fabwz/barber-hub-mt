<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\Services;

use Src\Availability\Slots\Domain\ValueObjects\AssignmentCandidate;
use Src\Availability\Slots\Domain\ValueObjects\CollaboratorId;

final class AnyAvailableAssigner
{
    /**
     * Choose who takes a space when the customer accepts "any available" (DI-04): whoever has the fewest reservations that
     * day and, if they tie, the one registered first. The candidates are already free and offer the service, so nothing else
     * is looked at. The result is the same for the same candidates, whatever order they come in.
     *
     * @param  list<AssignmentCandidate>  $candidates
     */
    public function choose(array $candidates): ?CollaboratorId
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (AssignmentCandidate $a, AssignmentCandidate $b): int => [$a->reservationsThatDay, $a->seniorityRank, $a->collaboratorId->value]
            <=> [$b->reservationsThatDay, $b->seniorityRank, $b->collaboratorId->value]);

        return $candidates[0]->collaboratorId;
    }
}
