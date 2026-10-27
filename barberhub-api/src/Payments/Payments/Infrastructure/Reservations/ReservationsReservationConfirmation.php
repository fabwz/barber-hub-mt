<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Reservations;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Reservations\Reservations\Application\UseCases\ConfirmReservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\PaymentWindowExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;

final readonly class ReservationsReservationConfirmation implements ReservationConfirmation
{
    /** Receive the Reservations use case that confirms a temporary reservation. */
    public function __construct(private ConfirmReservation $confirmReservation) {}

    /** Confirm through Reservations; whatever stops the confirmation of a reservation answers false instead of failing. */
    public function confirm(string $reservationId, DateTimeImmutable $now): bool
    {
        try {
            $this->confirmReservation->execute($reservationId, $now);
        } catch (PaymentWindowExpiredException|TransitionNotAllowedException|ReservationNotFoundException|OverlappingReservationException) {
            return false;
        }

        return true;
    }
}
