<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Identity;

use Src\Catalog\Invitations\Application\Contracts\InvitedMemberships;
use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Application\UseCases\FindMembershipOfUser;
use Src\Identity\Memberships\Application\UseCases\GrantCollaboratorMembership;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class IdentityInvitedMemberships implements InvitedMemberships
{
    /** Receive the Identity use cases that grant and find roles. */
    public function __construct(
        private GrantCollaboratorMembership $grant,
        private FindMembershipOfUser $findMembership,
    ) {}

    /** Make the person a collaborator through the Identity context; it refuses a person who already has a role there (UP-07). */
    public function grantCollaboratorRole(BarbershopId $barbershopId, string $userId): void
    {
        $this->grant->execute(new CollaboratorMembershipCommand($barbershopId->value, $userId));
    }

    /** Ask Identity for the role of the person in the barbershop. */
    public function membershipIdOf(BarbershopId $barbershopId, string $userId): ?string
    {
        return $this->findMembership->execute($userId, $barbershopId->value);
    }
}
