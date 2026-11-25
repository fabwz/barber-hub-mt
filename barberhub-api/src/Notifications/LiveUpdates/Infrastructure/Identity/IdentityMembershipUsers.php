<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Identity;

use Src\Identity\Memberships\Application\UseCases\ListMembershipUsers;
use Src\Notifications\LiveUpdates\Application\Contracts\MembershipUsers;

final readonly class IdentityMembershipUsers implements MembershipUsers
{
    /** Receive the Identity use case that tells which account holds a membership. */
    public function __construct(private ListMembershipUsers $membershipUsers) {}

    /** Find the account behind the membership of the barbershop through the Identity module; a membership of another barbershop has none. */
    public function userOf(string $membershipId, string $barbershopId): ?string
    {
        return $this->membershipUsers->execute([$membershipId], $barbershopId)[$membershipId] ?? null;
    }
}
