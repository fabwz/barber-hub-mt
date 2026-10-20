<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateOwnCollaboratorProfile
{
    /** Receive the collaborator repository port. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Give the owner a collaborator profile with their own schedule. The owner keeps the owner role and gets no other
     * membership: the role lives in the membership and the profile is what has an agenda (CO-02, UP-07).
     *
     * @throws CollaboratorAlreadyExistsException
     */
    public function execute(string $ownerUserId): CollaboratorWithAccount
    {
        $userId = new UserId($ownerUserId);

        if ($this->collaborators->findByUser($userId) !== null) {
            throw CollaboratorAlreadyExistsException::create();
        }

        $collaborator = Collaborator::create($this->collaborators->nextId(), $userId);
        $this->collaborators->save($collaborator);

        return $this->attachAccounts->toOne($collaborator);
    }
}
