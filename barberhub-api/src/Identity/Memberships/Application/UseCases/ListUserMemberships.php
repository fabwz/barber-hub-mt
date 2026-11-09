<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Application\DTOs\UserMembership;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ListUserMemberships
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Give the active roles a person holds, one per barbershop (UP-07). It only reads memberships of that person, so
     * it works without a barbershop in course (UP-08).
     *
     * @return list<UserMembership>
     */
    public function execute(string $userId): array
    {
        return array_map(
            fn (Membership $membership): UserMembership => new UserMembership($membership->barbershopId->value, $membership->role->value),
            $this->memberships->activeOf(new UserId($userId)),
        );
    }
}
