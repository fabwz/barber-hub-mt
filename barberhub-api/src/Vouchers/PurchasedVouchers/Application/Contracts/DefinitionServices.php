<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

use Src\Vouchers\PurchasedVouchers\Application\DTOs\CoveredService;

interface DefinitionServices
{
    /**
     * The services each voucher definition of the current barbershop covers, read from the live definition, on sale or withdrawn
     * (BO-06). A definition that is not found is left out.
     *
     * @param  list<string>  $definitionIds
     * @return array<string, list<CoveredService>> Indexed by the identifier of the definition.
     */
    public function of(array $definitionIds): array;
}
