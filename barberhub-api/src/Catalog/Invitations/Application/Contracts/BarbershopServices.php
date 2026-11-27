<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

interface BarbershopServices
{
    /**
     * Pick, among the given identifiers, the services that exist in the current barbershop (CO-01).
     *
     * @param  list<string>  $serviceIds
     * @return list<string>
     */
    public function existingAmong(array $serviceIds): array;
}
