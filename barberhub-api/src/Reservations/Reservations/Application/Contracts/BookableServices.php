<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\BookableService;

interface BookableServices
{
    /** The service of the current barbershop with the price and duration in force now; none if it does not exist or is inactive (SV-03, SV-04). */
    public function find(string $serviceId): ?BookableService;
}
