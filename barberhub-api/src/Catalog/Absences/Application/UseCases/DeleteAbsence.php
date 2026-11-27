<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\UseCases;

use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceNotFoundException;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceId;

final readonly class DeleteAbsence
{
    /** Receive the absence repository port. */
    public function __construct(private AbsenceRepository $absences) {}

    /**
     * Remove an absence of the current barbershop so its spaces are offered again (RF-07).
     *
     * @throws AbsenceNotFoundException
     */
    public function execute(string $absenceId): void
    {
        $absence = $this->absences->find(new AbsenceId($absenceId)) ?? throw AbsenceNotFoundException::create();

        $this->absences->delete($absence);
    }
}
