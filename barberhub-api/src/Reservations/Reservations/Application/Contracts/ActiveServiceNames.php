<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface ActiveServiceNames
{
    /**
     * The names of the active services of the current barbershop, and nothing else: they are what the language provider is told (IA-06).
     *
     * @return list<string>
     */
    public function all(): array;
}
