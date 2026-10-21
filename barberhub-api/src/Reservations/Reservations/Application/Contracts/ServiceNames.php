<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface ServiceNames
{
    /**
     * The name of every service of the current barbershop, active or not, indexed by service: a reservation keeps showing
     * the service it was made for even after the owner inactivates it (SV-04).
     *
     * @return array<string, string>
     */
    public function all(): array;
}
