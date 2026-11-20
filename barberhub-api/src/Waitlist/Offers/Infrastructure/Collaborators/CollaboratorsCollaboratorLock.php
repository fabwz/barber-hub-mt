<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\LockCollaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;

final readonly class CollaboratorsCollaboratorLock implements CollaboratorLock
{
    /** Receive the Collaborators use case that locks a collaborator, the same the reservations use. */
    public function __construct(private LockCollaborator $lockCollaborator) {}

    /** Lock the collaborator through the Collaborators module; one that is not of the barbershop answers false. */
    public function lock(string $collaboratorId): bool
    {
        try {
            $this->lockCollaborator->execute($collaboratorId);

            return true;
        } catch (CollaboratorNotFoundException) {
            return false;
        }
    }
}
