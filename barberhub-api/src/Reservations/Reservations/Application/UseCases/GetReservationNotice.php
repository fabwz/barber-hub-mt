<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\DTOs\ReservationNotice;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

/** The facts of a reservation of the current barbershop that the notices need, for a process that has no person (a task of the queue). */
final readonly class GetReservationNotice
{
    public function __construct(private ReservationRepository $reservations) {}

    /** The facts, or null when the reservation does not exist in this barbershop. A temporary reservation is not confirmed, even before it is overdue. */
    public function execute(string $reservationId, DateTimeImmutable $now): ?ReservationNotice
    {
        $reservation = $this->reservations->find(new ReservationId($reservationId));

        if ($reservation === null) {
            return null;
        }

        return new ReservationNotice(
            $reservation->statusAt($now) === ReservationStatus::Confirmed,
            $reservation->interval->start,
            $reservation->interval->end,
            $reservation->customerMembershipId->value,
            $reservation->serviceId->value,
            $reservation->collaboratorId->value,
        );
    }
}
