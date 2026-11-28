<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\OperatorCannotHoldRoleException;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\Contracts\PlatformOperators;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class AssignBarbershopOwner
{
    /** Receive the membership repository port and the one that tells whether an account is a platform operator. */
    public function __construct(
        private MembershipRepository $memberships,
        private PlatformOperators $operators,
    ) {}

    /**
     * Make a person an active owner of a barbershop (UP-01, UP-05).
     *
     * @throws RoleAlreadyAssignedException
     * @throws OperatorCannotHoldRoleException when the account is a platform operator, who holds no role in any barbershop (PL-01, PL-02)
     */
    public function execute(AssignBarbershopOwnerCommand $command): Membership
    {
        $userId = new UserId($command->userId);

        if ($this->operators->isOperator($userId)) {
            throw OperatorCannotHoldRoleException::create();
        }

        $membership = Membership::create(
            $this->memberships->nextId(),
            $userId,
            new BarbershopId($command->barbershopId),
            Role::Owner,
        );

        $this->memberships->save($membership);

        return $membership;
    }
}
