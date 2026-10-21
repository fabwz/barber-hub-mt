<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\LockCollaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException as CollaboratorMissing;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;

final readonly class CollaboratorsCollaboratorLock implements CollaboratorLock
{
    /** Receive the Collaborators use case that locks a collaborator. */
    public function __construct(private LockCollaborator $lockCollaborator) {}

    /** Lock the collaborator through the Collaborators module and answer its absence with the not-found error of this module. */
    public function lock(string $collaboratorId): void
    {
        try {
            $this->lockCollaborator->execute($collaboratorId);
        } catch (CollaboratorMissing) {
            throw CollaboratorNotFoundException::create();
        }
    }
}
