<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\DTOs\BookableStaffMember;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\UseCases\ListBookableStaff;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Reservations\Reservations\Application\Contracts\ProposableCollaborators;
use Src\Reservations\Reservations\Application\DTOs\ProposableCollaborator;

final readonly class CollaboratorsProposableCollaborators implements ProposableCollaborators
{
    /** Receive the Collaborators use cases that list who can be booked for a service and who is active. */
    public function __construct(private ListBookableStaff $listBookable, private ListCollaborators $listCollaborators) {}

    /**
     * Read the active collaborators who offer the service through the Collaborators module; it gives only identifier and name.
     *
     * @return list<ProposableCollaborator>
     */
    public function offering(string $serviceId): array
    {
        return array_map(fn (BookableStaffMember $member): ProposableCollaborator => new ProposableCollaborator($member->collaboratorId, $member->name), $this->listBookable->execute($serviceId, null));
    }

    /**
     * Read the active collaborators through the Collaborators module; it gives only identifier and name, never the account.
     *
     * @return list<ProposableCollaborator>
     */
    public function active(): array
    {
        $active = array_filter($this->listCollaborators->execute(), fn (CollaboratorWithAccount $member): bool => $member->collaborator->active);

        return array_values(array_map(fn (CollaboratorWithAccount $member): ProposableCollaborator => new ProposableCollaborator($member->collaborator->id->value, $member->name), $active));
    }
}
