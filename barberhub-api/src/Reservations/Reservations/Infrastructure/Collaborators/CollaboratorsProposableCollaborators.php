<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\DTOs\BookableStaffMember;
use Src\Catalog\Collaborators\Application\UseCases\ListBookableStaff;
use Src\Reservations\Reservations\Application\Contracts\ProposableCollaborators;
use Src\Reservations\Reservations\Application\DTOs\ProposableCollaborator;

final readonly class CollaboratorsProposableCollaborators implements ProposableCollaborators
{
    /** Receive the Collaborators use case that lists who can be booked for a service. */
    public function __construct(private ListBookableStaff $listBookable) {}

    /**
     * Read the active collaborators who offer the service through the Collaborators module; it gives only identifier and name.
     *
     * @return list<ProposableCollaborator>
     */
    public function offering(string $serviceId): array
    {
        return array_map(fn (BookableStaffMember $member): ProposableCollaborator => new ProposableCollaborator($member->collaboratorId, $member->name), $this->listBookable->execute($serviceId, null));
    }
}
