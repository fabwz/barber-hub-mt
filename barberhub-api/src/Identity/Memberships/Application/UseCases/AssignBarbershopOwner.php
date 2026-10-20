<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class AssignBarbershopOwner
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Make a person an active owner of a barbershop (UP-01, UP-05).
     *
     * @throws RoleAlreadyAssignedException
     */
    public function execute(AssignBarbershopOwnerCommand $command): Membership
    {
        $membership = Membership::create(
            $this->memberships->nextId(),
            new UserId($command->userId),
            new BarbershopId($command->barbershopId),
            Role::Owner,
        );

        $this->memberships->save($membership);

        return $membership;
    }
}
