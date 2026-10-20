<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;

final readonly class GetCollaborator
{
    /** Receive the collaborator repository port. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Read one collaborator of the current barbershop.
     *
     * @throws CollaboratorNotFoundException
     */
    public function execute(string $collaboratorId): CollaboratorWithAccount
    {
        $collaborator = $this->collaborators->find(new CollaboratorId($collaboratorId))
            ?? throw CollaboratorNotFoundException::create();

        return $this->attachAccounts->toOne($collaborator);
    }
}
