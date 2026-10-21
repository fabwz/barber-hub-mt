<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\FindCollaboratorOfUser;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;

final readonly class CollaboratorsCollaboratorDirectory implements CollaboratorDirectory
{
    /** Receive the Collaborators use cases that find a profile and list the collaborators. */
    public function __construct(
        private FindCollaboratorOfUser $findCollaborator,
        private ListCollaborators $listCollaborators,
    ) {}

    /** Find the profile of the account through the Collaborators module. */
    public function idOfUser(string $userId): ?string
    {
        return $this->findCollaborator->execute($userId);
    }

    /**
     * Read the collaborators through the Collaborators module and keep only their names, never their emails.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->listCollaborators->execute() as $collaborator) {
            $names[$collaborator->collaborator->id->value] = $collaborator->name;
        }

        return $names;
    }
}
