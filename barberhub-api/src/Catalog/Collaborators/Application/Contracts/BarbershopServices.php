<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\Contracts;

interface BarbershopServices
{
    /**
     * Pick, among the given identifiers, the services that exist in the current barbershop, active or not (CO-01).
     *
     * @param  list<string>  $serviceIds
     * @return list<string>
     */
    public function existingAmong(array $serviceIds): array;
}
