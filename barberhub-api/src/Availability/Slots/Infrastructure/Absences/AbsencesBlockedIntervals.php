<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Absences;

use Src\Catalog\Absences\Application\UseCases\ListAbsenceIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class AbsencesBlockedIntervals
{
    /** Receive the Absences use case that lists the intervals a collaborator is absent. */
    public function __construct(private ListAbsenceIntervals $listAbsences) {}

    /**
     * List the intervals some collaborators are absent inside a window through the Absences module (DI-01, CO-06).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window): array
    {
        return $this->listAbsences->execute($collaboratorIds, $window);
    }
}
