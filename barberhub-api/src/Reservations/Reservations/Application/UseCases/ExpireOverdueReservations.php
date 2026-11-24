<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Events\ReservationExpired;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;

final readonly class ExpireOverdueReservations
{
    /** Receive the reservation repository, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private ReservationRepository $reservations,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Mark as expired the temporary reservations of a collaborator whose payment window closed (RE-05), each with its
     * history entry authored by the system (RE-09), and release their space for the exclusion of the database. It is lazy:
     * nobody calls it on a schedule; the operations that need the space of the collaborator run it first. The caller
     * holds the lock of the collaborator and the transaction. Running it again finds nothing, so it is idempotent. Each reservation it
     * expires announces that it expired (ReservationExpired, for the live agenda, RF-36) and the time it held as released (SpaceReleased), so the waitlist can offer it (RF-31).
     *
     * @return int The number of reservations it expired.
     */
    public function execute(string $collaboratorId, DateTimeImmutable $now): int
    {
        $overdue = $this->reservations->lockOverdueOf(new CollaboratorId($collaboratorId), $now);

        foreach ($overdue as $reservation) {
            $reservation->expire($now, Author::system());
            $this->reservations->save($reservation);
            $this->events->publish(new ReservationExpired(
                $this->context->current()->value,
                $reservation->id->value,
                $reservation->collaboratorId->value,
                $reservation->interval->start,
                $reservation->interval->end,
            ));
            $this->events->publish(new SpaceReleased(
                $this->context->current()->value,
                $reservation->id->value,
                $reservation->collaboratorId->value,
                $reservation->interval->start,
                $reservation->blockedUntil,
            ));
        }

        return count($overdue);
    }
}
