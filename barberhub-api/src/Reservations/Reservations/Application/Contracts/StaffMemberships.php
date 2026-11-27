<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface StaffMemberships
{
    /** The identifier of the role a person holds in the current barbershop, whichever it is, or none if they hold none there. */
    public function of(string $userId): ?string;
}
