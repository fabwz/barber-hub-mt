<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class GrantCollaboratorMembership
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Make a person an active collaborator of a barbershop (UP-04, UP-07).
     *
     * @throws RoleAlreadyAssignedException
     */
    public function execute(CollaboratorMembershipCommand $command): Membership
    {
        $membership = Membership::create(
            $this->memberships->nextId(),
            new UserId($command->userId),
            new BarbershopId($command->barbershopId),
            Role::Collaborator,
        );

        $this->memberships->save($membership);

        return $membership;
    }
}
