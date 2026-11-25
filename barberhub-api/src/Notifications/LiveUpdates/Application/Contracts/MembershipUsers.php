<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\Contracts;

interface MembershipUsers
{
    /** Identifier of the account that holds a customer role of a barbershop, or null when the role has no account (RF-35). */
    public function userOf(string $membershipId, string $barbershopId): ?string;
}
