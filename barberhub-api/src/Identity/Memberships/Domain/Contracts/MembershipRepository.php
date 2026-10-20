<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\Contracts;

use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

interface MembershipRepository
{
    /** Generate the identity for a new membership. */
    public function nextId(): MembershipId;

    /**
     * Persist a new or modified membership.
     *
     * @throws RoleAlreadyAssignedException
     */
    public function save(Membership $membership): void;

    /** Find the role of a person in a barbershop. */
    public function findByUserAndBarbershop(UserId $userId, BarbershopId $barbershopId): ?Membership;
}
