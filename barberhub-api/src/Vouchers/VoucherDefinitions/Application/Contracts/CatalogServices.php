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

    /**
     * The name of each of the given services that exists in the current barbershop, active or not, indexed by its identifier.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, string>
     */
    public function namesOf(array $serviceIds): array;
}
