<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorNames;

final readonly class CollaboratorsCollaboratorNames implements CollaboratorNames
{
    /** Receive the Collaborators use case that lists the collaborators of the barbershop. */
    public function __construct(private ListCollaborators $listCollaborators) {}

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
