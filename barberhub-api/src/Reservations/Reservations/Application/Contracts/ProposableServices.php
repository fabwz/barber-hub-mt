<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\ProposableService;

interface ProposableServices
{
    /**
     * The active services of the current barbershop with their price, and no other: they are what a phrase may refer to and what
     * the language provider is told by name (SV-04, IA-06).
     *
     * @return list<ProposableService>
     */
    public function active(): array;
}
