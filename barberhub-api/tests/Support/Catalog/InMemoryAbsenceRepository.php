<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use DateTimeImmutable;
use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Domain\Entities\Absence;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceId;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class InMemoryAbsenceRepository implements AbsenceRepository
{
    /** @var array<string, Absence> */
    private array $absences = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): AbsenceId
    {
        return new AbsenceId(sprintf('0192f0c5-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the absence. */
    public function save(Absence $absence): void
    {
        $this->absences[$absence->id->value] = $absence;
    }

    /** Find an absence by its identity. */
    public function find(AbsenceId $id): ?Absence
    {
        return $this->absences[$id->value] ?? null;
    }

    /** Remove the absence. */
    public function delete(Absence $absence): void
    {
        unset($this->absences[$absence->id->value]);
    }

    /**
     * The absences that have not ended, the earliest first.
     *
     * @return list<Absence>
     */
    public function current(DateTimeImmutable $now): array
    {
        $current = array_values(array_filter($this->absences, fn (Absence $absence): bool => $absence->interval->end > $now));
        usort($current, fn (Absence $a, Absence $b): int => $a->interval->start <=> $b->interval->start);

        return $current;
    }

    /**
     * The intervals of some collaborators that overlap a window.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window): array
    {
        $found = [];

        foreach ($this->absences as $absence) {
            if (in_array($absence->collaboratorId->value, $collaboratorIds, true) && $absence->interval->overlaps($window)) {
                $found[$absence->collaboratorId->value][] = $absence->interval;
            }
        }

        return $found;
    }
}
