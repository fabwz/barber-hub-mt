<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\Contracts;

interface CatalogServices
{
    /**
     * Pick, among the given identifiers, the services that exist in the current barbershop, active or not (BO-01, MT-04).
     *
     * @param  list<string>  $serviceIds
     * @return list<string>
     */
    public function existingAmong(array $serviceIds): array;
}
