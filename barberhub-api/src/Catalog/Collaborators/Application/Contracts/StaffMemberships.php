<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\Contracts;

use Src\Shared\Domain\ValueObjects\BarbershopId;

interface StaffMemberships
{
    /** Make a person a collaborator of a barbershop (UP-07). */
    public function grantCollaboratorRole(BarbershopId $barbershopId, string $userId): void;

    /** Switch off the collaborator role of a person; an owner keeps theirs (UP-05). */
    public function deactivateCollaboratorRole(BarbershopId $barbershopId, string $userId): void;

    /** Switch the collaborator role of a person back on. */
    public function activateCollaboratorRole(BarbershopId $barbershopId, string $userId): void;
}
