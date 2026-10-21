<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;

final readonly class LockCollaborator
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /**
     * Lock the collaborator of the current barbershop until the open transaction ends. The reservation uses it to decide
     * one request at a time per collaborator (RE-01); the caller must have a transaction open.
     *
     * @throws CollaboratorNotFoundException when the collaborator is not in the current barbershop
     */
    public function execute(string $collaboratorId): void
    {
        $this->collaborators->lock(new CollaboratorId($collaboratorId)) || throw CollaboratorNotFoundException::create();
    }
}
