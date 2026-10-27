<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\PaymentWindowExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;

final readonly class ConfirmReservation
{
    /** Receive the reservation repository. */
    public function __construct(private ReservationRepository $reservations) {}

    /**
     * Confirm a temporary reservation once its payment is confirmed (PA-06); the payments module calls it. The system is
     * the author (RE-09). It does not open a transaction: it is atomic with whatever calls it, which must hold one. It
     * locks the row, so a confirmation and an expiration of the same reservation run in turn. A reservation whose window
     * closed, even if nobody marked it expired yet, and one that is no longer temporary are refused (RE-04, RE-05); the
     * refund of a late payment (PA-07) is pending.
     *
     * @throws ReservationNotFoundException
     * @throws PaymentWindowExpiredException
     * @throws TransitionNotAllowedException
     * @throws OverlappingReservationException when the customer got another confirmed reservation at that time (RE-06)
     */
    public function execute(string $reservationId, DateTimeImmutable $now): Reservation
    {
        $reservation = $this->reservations->findForUpdate(new ReservationId($reservationId)) ?? throw ReservationNotFoundException::create();

        $reservation->confirm($now, Author::system());
        $this->reservations->save($reservation);

        return $reservation;
    }
}
