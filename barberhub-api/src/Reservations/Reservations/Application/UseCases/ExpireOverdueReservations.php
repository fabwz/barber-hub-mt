<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;

final readonly class ExpireOverdueReservations
{
    /** Receive the reservation repository. */
    public function __construct(private ReservationRepository $reservations) {}

    /**
     * Mark as expired the temporary reservations of a collaborator whose payment window closed (RE-05), each with its
     * history entry authored by the system (RE-09), and release their space for the exclusion of the database. It is lazy:
     * nobody calls it on a schedule; the operations that need the space of the collaborator run it first. The caller
     * holds the lock of the collaborator and the transaction. Running it again finds nothing, so it is idempotent.
     *
     * @return int The number of reservations it expired.
     */
    public function execute(string $collaboratorId, DateTimeImmutable $now): int
    {
        $overdue = $this->reservations->lockOverdueOf(new CollaboratorId($collaboratorId), $now);

        foreach ($overdue as $reservation) {
            $reservation->expire($now, Author::system());
            $this->reservations->save($reservation);
        }

        return count($overdue);
    }
}
