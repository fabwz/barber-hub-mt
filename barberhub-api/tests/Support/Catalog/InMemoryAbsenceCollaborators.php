<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Absences\Application\Contracts\AbsenceCollaborators;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;

/** The collaborators an absence can target, kept in memory: each is active or inactive, any other is not in the barbershop. */
final class InMemoryAbsenceCollaborators implements AbsenceCollaborators
{
    /** @var array<string, bool> */
    private array $active = [];

    /** @var list<string> */
    public array $locked = [];

    /** Register a collaborator of the barbershop. */
    public function add(string $collaboratorId, bool $active = true): void
    {
        $this->active[$collaboratorId] = $active;
    }

    /** Record the lock and tell whether the collaborator is active. */
    public function lockAndCheckActive(string $collaboratorId): bool
    {
        $this->locked[] = $collaboratorId;

        return $this->active[$collaboratorId] ?? throw CollaboratorNotFoundException::create();
    }
}
