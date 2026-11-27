<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Collaborators;

use Src\Catalog\Absences\Application\Contracts\AbsenceCollaborators;
use Src\Catalog\Collaborators\Application\UseCases\LockCollaboratorAndCheckActive;

final readonly class CollaboratorsAbsenceCollaborators implements AbsenceCollaborators
{
    /** Receive the Collaborators use case that takes the lock of a collaborator and reads whether they are active. */
    public function __construct(private LockCollaboratorAndCheckActive $lock) {}

    /** Take the lock through the Collaborators module, the same one the reservations take. */
    public function lockAndCheckActive(string $collaboratorId): bool
    {
        return $this->lock->execute($collaboratorId);
    }
}
