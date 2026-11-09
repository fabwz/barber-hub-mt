<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Catalog\Barbershops\Application\DTOs\BarbershopRole;

interface PersonMemberships
{
    /**
     * Roles a person holds through their active memberships, one per barbershop.
     *
     * @return list<BarbershopRole>
     */
    public function activeOf(string $userId): array;
}
