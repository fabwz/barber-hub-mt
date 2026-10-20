<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Shared\Domain\ValueObjects\BarbershopId;

interface OwnerMemberships
{
    /** Make a person the owner of a barbershop. */
    public function assignOwner(BarbershopId $barbershopId, string $userId): void;
}
