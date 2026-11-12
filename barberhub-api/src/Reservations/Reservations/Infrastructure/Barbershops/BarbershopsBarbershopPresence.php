<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopDetails;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Reservations\Reservations\Application\Contracts\BarbershopPresence;

final readonly class BarbershopsBarbershopPresence implements BarbershopPresence
{
    /** Receive the Barbershops use case that reads the current barbershop. */
    public function __construct(private GetBarbershopDetails $getBarbershop) {}

    /** Ask the Barbershops module whether the current barbershop exists. */
    public function exists(): bool
    {
        try {
            $this->getBarbershop->execute();
        } catch (BarbershopNotFoundException) {
            return false;
        }

        return true;
    }
}
