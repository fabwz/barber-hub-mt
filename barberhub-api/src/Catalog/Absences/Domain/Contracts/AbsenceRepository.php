<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Contracts;

use DateTimeImmutable;
use Src\Catalog\Absences\Domain\Entities\Absence;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceId;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface AbsenceRepository
{
    /** Generate the identity for a new absence. */
    public function nextId(): AbsenceId;

    /** Persist a new absence in the current barbershop. */
    public function save(Absence $absence): void;

    /** Find an absence of the current barbershop. */
    public function find(AbsenceId $id): ?Absence;

    /** Remove an absence of the current barbershop. */
    public function delete(Absence $absence): void;

    /**
     * List the absences of the current barbershop that have not ended at the instant, the earliest first.
     *
     * @return list<Absence>
     */
    public function current(DateTimeImmutable $now): array;

    /**
     * The intervals some collaborators of the current barbershop are absent that overlap a window, by collaborator; the ones
     * without any are absent from the result.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window): array;
}
