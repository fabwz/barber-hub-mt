<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

interface CoveringDefinitions
{
    /**
     * The identifiers of the voucher definitions of the current barbershop that cover the service, on sale or withdrawn (BO-06).
     *
     * @return list<string>
     */
    public function idsCovering(string $serviceId): array;
}
