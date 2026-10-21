<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final class InMemoryMembershipRepository implements MembershipRepository
{
    /** @var array<string, Membership> */
    private array $memberships = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): MembershipId
    {
        return new MembershipId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the membership enforcing one role per person and barbershop. */
    public function save(Membership $membership): void
    {
        $existing = $this->findByUserAndBarbershop($membership->userId, $membership->barbershopId);

        if ($existing !== null && ! $existing->id->equals($membership->id)) {
            throw RoleAlreadyAssignedException::create();
        }

        $this->memberships[$membership->id->value] = $membership;
    }

    /** Find the role of a person in a barbershop. */
    public function findByUserAndBarbershop(UserId $userId, BarbershopId $barbershopId): ?Membership
    {
        return array_find(
            $this->memberships,
            fn (Membership $membership): bool => $membership->userId->equals($userId) && $membership->barbershopId->equals($barbershopId),
        );
    }

    /**
     * Find the accounts behind some memberships of a barbershop, indexed by membership.
     *
     * @param  list<MembershipId>  $ids
     * @return array<string, UserId>
     */
    public function usersOf(array $ids, BarbershopId $barbershopId): array
    {
        $users = [];

        foreach ($ids as $id) {
            $membership = $this->memberships[$id->value] ?? null;

            if ($membership !== null && $membership->barbershopId->equals($barbershopId)) {
                $users[$id->value] = $membership->userId;
            }
        }

        return $users;
    }

    /** Count the stored memberships. */
    public function count(): int
    {
        return count($this->memberships);
    }
}
