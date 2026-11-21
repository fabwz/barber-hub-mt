<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\EconomicConsequence;

final readonly class CancelledReservation
{
    /** Carry a cancelled reservation with the class of its cancellation and the consequence applied to its deposit and its voucher session. */
    public function __construct(
        public Reservation $reservation,
        public CancellationClass $class,
        public EconomicConsequence $consequence,
    ) {}
}
