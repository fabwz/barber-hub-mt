<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\DTOs\ChargePolicy;

interface BarbershopChargePolicy
{
    /** How the current barbershop charges now: nothing, a deposit or the full price (PA-01). */
    public function current(): ChargePolicy;
}
