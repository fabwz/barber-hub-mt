<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\EnsureCustomerMembership;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class MembershipsCustomerMemberships implements CustomerMemberships
{
    /** Receive the Memberships use case and the port that tells which barbershop the operation belongs to. */
    public function __construct(
        private EnsureCustomerMembership $ensureMembership,
        private BarbershopContext $context,
    ) {}

    /** Give the customer role of the person in the current barbershop through the Memberships module, creating it the first time. */
    public function ensure(string $userId): string
    {
        return $this->ensureMembership->execute($userId, $this->context->current()->value);
    }
}
