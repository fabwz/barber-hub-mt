<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;

final readonly class ListCollaborators
{
    /** Receive the collaborator repository port. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * List every collaborator of the current barbershop, active or not, for its owner.
     *
     * @return list<CollaboratorWithAccount>
     */
    public function execute(): array
    {
        return $this->attachAccounts->to($this->collaborators->all());
    }
}
