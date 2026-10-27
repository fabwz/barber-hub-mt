<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface CancellationDeadlines
{
    /** The hours before a reservation up to which the current barbershop lets a customer cancel or reschedule free, in force now (CA-01). */
    public function current(): int;
}
