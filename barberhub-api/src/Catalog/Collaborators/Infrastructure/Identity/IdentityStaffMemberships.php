<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Identity;

use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Application\UseCases\ActivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\DeactivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\GrantCollaboratorMembership;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class IdentityStaffMemberships implements StaffMemberships
{
    /** Receive the Identity use cases that grant and switch the collaborator role. */
    public function __construct(
        private GrantCollaboratorMembership $grant,
        private DeactivateCollaboratorMembership $deactivate,
        private ActivateCollaboratorMembership $activate,
    ) {}

    /** Make the person a collaborator through the Identity context. */
    public function grantCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        $this->grant->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }

    /** Switch the collaborator role off through the Identity context. */
    public function deactivateCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        $this->deactivate->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }

    /** Switch the collaborator role on through the Identity context. */
    public function activateCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        $this->activate->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }
}
