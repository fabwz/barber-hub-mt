<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Listeners;

use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Shared\Domain\Contracts\Clock;
use Src\Waitlist\Offers\Application\UseCases\ReleaseOfferOfCancelledReservation;

/**
 * Reacts to a reservation that was cancelled (LE-05). It is not queued: it runs inside the transaction of the cancellation, with its barbershop already
 * set, so the cancellation of the reservation made for an offer and the closing of that offer are one fact and nothing can leave the offer open
 * while its reservation is gone. The use case swallows what it cannot do, so a reservation that no offer points at is cancelled as before.
 */
final readonly class ReleaseOfferOnReservationCancelled
{
    /** Receive the use case that closes the offer of the reservation and the clock. */
    public function __construct(
        private ReleaseOfferOfCancelledReservation $release,
        private Clock $clock,
    ) {}

    /** Close the offer that waits for the cancelled reservation, if one does. */
    public function handle(ReservationCancelled $event): void
    {
        $this->release->execute($event->reservationId, $this->clock->now());
    }
}
