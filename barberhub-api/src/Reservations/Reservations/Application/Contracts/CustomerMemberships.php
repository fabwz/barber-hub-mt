<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface CustomerMemberships
{
    /**
     * The identifier of the customer role of a person in the current barbershop, created if they have none (UP-09). An
     * owner or collaborator of the barbershop is refused. Called inside the transaction of the reservation, so a rollback
     * takes the membership with it.
     */
    public function ensure(string $userId): string;
}
