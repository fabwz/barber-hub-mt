<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Domain\Entities\Absence;

final readonly class ListAbsences
{
    /** Receive the absence repository port. */
    public function __construct(private AbsenceRepository $absences) {}

    /**
     * List the absences of the current barbershop that have not ended yet, the earliest first (RF-07).
     *
     * @return list<Absence>
     */
    public function execute(DateTimeImmutable $now): array
    {
        return $this->absences->current($now);
    }
}
