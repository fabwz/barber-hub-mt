<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface BarbershopPresence
{
    /** Whether the current barbershop exists: the mode of a customer operation lets any address through, because a person may have no role there yet (RD-18). */
    public function exists(): bool;
}
