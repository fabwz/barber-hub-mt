<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\EnsureCustomerMembership;
use Src\Identity\Memberships\Application\UseCases\FindCustomerMembership;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;

final readonly class MembershipsCustomerMemberships implements CustomerMemberships
{
    /** Receive the Memberships use cases that give and find the customer role, and the port that tells which barbershop the operation belongs to. */
    public function __construct(
        private EnsureCustomerMembership $ensureMembership,
        private FindCustomerMembership $findMembership,
        private BarbershopContext $context,
    ) {}

    /** Give the customer role of the person in the current barbershop through the Memberships module, creating it the first time. */
    public function ensure(string $userId): string
    {
        return $this->ensureMembership->execute($userId, $this->context->current()->value);
    }

    /** Find the customer role of the person in the current barbershop through the Memberships module, creating nothing. */
    public function find(string $userId): ?string
    {
        return $this->findMembership->execute($userId, $this->context->current()->value);
    }
}
