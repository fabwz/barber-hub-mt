<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

interface CustomerMemberships
{
    /**
     * The identifier of the customer role of a person in the current barbershop, created if they have none (UP-09). An owner or
     * collaborator of the barbershop is refused. Called inside the transaction of the purchase, so a rollback takes the role with it.
     */
    public function ensure(string $userId): string;

    /** The identifier of the customer role of a person in the current barbershop, or none if they have not used it yet; it creates nothing. */
    public function find(string $userId): ?string;
}
