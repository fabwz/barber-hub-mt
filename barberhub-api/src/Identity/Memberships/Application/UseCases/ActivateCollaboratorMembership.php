<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ActivateCollaboratorMembership
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /** Switch the collaborator role of a person back on; any other role is left as it is. */
    public function execute(CollaboratorMembershipCommand $command): void
    {
        $membership = $this->memberships->findByUserAndBarbershop(
            new UserId($command->userId),
            new BarbershopId($command->barbershopId),
        );

        if ($membership === null || $membership->role !== Role::Collaborator) {
            return;
        }

        $this->memberships->save($membership->activate());
    }
}
