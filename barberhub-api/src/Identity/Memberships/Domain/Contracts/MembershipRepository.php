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

    /**
     * Find the accounts behind some memberships of a barbershop, indexed by membership; a membership of another barbershop
     * is skipped, so a foreign identifier reads like an unknown one (UP-06).
     *
     * @param  list<MembershipId>  $ids
     * @return array<string, UserId>
     */
    public function usersOf(array $ids, BarbershopId $barbershopId): array;
}
