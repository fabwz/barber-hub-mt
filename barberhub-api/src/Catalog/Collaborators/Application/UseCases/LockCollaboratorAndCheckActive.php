<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;

final readonly class LockCollaboratorAndCheckActive
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /**
     * Lock the collaborator of the current barbershop until the open transaction ends, as `LockCollaborator` does, and tell whether
     * they are active. The absences use it: they block the agenda of an active collaborator at the turn the reservations decide
     * (RE-01); the caller must have a transaction open.
     *
     * @throws CollaboratorNotFoundException when the collaborator is not in the current barbershop
     */
    public function execute(string $collaboratorId): bool
    {
        $id = new CollaboratorId($collaboratorId);

        $this->collaborators->lock($id) || throw CollaboratorNotFoundException::create();

        return ($this->collaborators->find($id) ?? throw CollaboratorNotFoundException::create())->active;
    }
}
