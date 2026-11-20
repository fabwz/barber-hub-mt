<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\FindCustomerMembership;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Waitlist\Offers\Application\Contracts\CustomerMemberships;

final readonly class MembershipsCustomerMemberships implements CustomerMemberships
{
    /** Receive the Memberships use case that finds the customer role of a person, and the port that tells which barbershop the operation belongs to. */
    public function __construct(
        private FindCustomerMembership $findMembership,
        private BarbershopContext $context,
    ) {}

    /** Find the customer role of the person in the current barbershop through the Memberships module, creating nothing. */
    public function find(string $userId): ?string
    {
        return $this->findMembership->execute($userId, $this->context->current()->value);
    }
}
