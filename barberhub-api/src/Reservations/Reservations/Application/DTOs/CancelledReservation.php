<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;

final readonly class CancelledReservation
{
    /** Carry a cancelled reservation with the class of its cancellation. */
    public function __construct(
        public Reservation $reservation,
        public CancellationClass $class,
    ) {}
}
