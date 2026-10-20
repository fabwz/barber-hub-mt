<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Application\UseCases\ActivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\DeactivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\GrantCollaboratorMembership;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\Identity\InMemoryMembershipRepository;

/** The staff memberships port answered by the Identity use cases over in-memory memberships, as the adapter does. */
final readonly class InMemoryStaffMemberships implements StaffMemberships
{
    public function __construct(private InMemoryMembershipRepository $memberships) {}

    /** Grant the collaborator role. */
    public function grantCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        new GrantCollaboratorMembership($this->memberships)->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }

    /** Switch the collaborator role off. */
    public function deactivateCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        new DeactivateCollaboratorMembership($this->memberships)->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }

    /** Switch the collaborator role on. */
    public function activateCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        new ActivateCollaboratorMembership($this->memberships)->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }
}
