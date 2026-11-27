<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\UseCases;

use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ListAbsenceIntervals
{
    /** Receive the absence repository port. */
    public function __construct(private AbsenceRepository $absences) {}

    /**
     * List the intervals some collaborators of the current barbershop are absent inside a window, by collaborator. The availability
     * reads it to take those spaces away (DI-01, CO-06).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function execute(array $collaboratorIds, TimeInterval $window): array
    {
        if ($collaboratorIds === []) {
            return [];
        }

        return $this->absences->between($collaboratorIds, $window);
    }
}
