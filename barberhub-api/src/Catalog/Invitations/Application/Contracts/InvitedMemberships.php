<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

use Src\Shared\Domain\ValueObjects\BarbershopId;

interface InvitedMemberships
{
    /** Make a person a collaborator of a barbershop; refused when they already have a role there (UP-07). */
    public function grantCollaboratorRole(BarbershopId $barbershopId, string $userId): void;

    /** The identifier of the role a person holds in a barbershop, or null when they have none. */
    public function membershipIdOf(BarbershopId $barbershopId, string $userId): ?string;
}
