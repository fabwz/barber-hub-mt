<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use Src\Notifications\Notices\Application\Contracts\ReservationLookup;
use Src\Notifications\Notices\Application\DTOs\ReservationFacts;

/** The reservations the test tells about, instead of the Reservations module. */
final class KnownReservations implements ReservationLookup
{
    /** @var array<string, ReservationFacts> */
    public array $items = [];

    /** The reservation, or null when the test did not tell about it. */
    public function find(string $reservationId): ?ReservationFacts
    {
        return $this->items[$reservationId] ?? null;
    }
}
