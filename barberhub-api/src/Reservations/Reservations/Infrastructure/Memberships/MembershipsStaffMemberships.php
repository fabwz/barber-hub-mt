<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\FindMembershipOfUser;
use Src\Reservations\Reservations\Application\Contracts\StaffMemberships;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class MembershipsStaffMemberships implements StaffMemberships
{
    /** Receive the Memberships use case that finds the role of a person and the port that tells which barbershop the operation belongs to. */
    public function __construct(
        private FindMembershipOfUser $findMembership,
        private BarbershopContext $context,
    ) {}

    /** Give the role of the person in the current barbershop through the Memberships module. */
    public function of(string $userId): ?string
    {
        return $this->findMembership->execute($userId, $this->context->current()->value);
    }
}
