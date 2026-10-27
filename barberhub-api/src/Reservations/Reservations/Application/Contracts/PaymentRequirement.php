<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface PaymentRequirement
{
    /** Tell whether the current barbershop charges, with a deposit or the full price, before it confirms a reservation (RE-07, PA-06). */
    public function isRequired(): bool;
}
