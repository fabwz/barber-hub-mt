<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Reservations\Reservations\Application\Contracts\ActiveCollaboratorNames;

final readonly class CollaboratorsActiveCollaboratorNames implements ActiveCollaboratorNames
{
    /** Receive the Collaborators use case that lists the collaborators. */
    public function __construct(private ListCollaborators $listCollaborators) {}

    /**
     * Read the collaborators through the Collaborators module and keep only the names of the active ones, never their emails.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $active = array_filter($this->listCollaborators->execute(), fn (CollaboratorWithAccount $member): bool => $member->collaborator->active);

        return array_values(array_map(fn (CollaboratorWithAccount $member): string => $member->name, $active));
    }
}
