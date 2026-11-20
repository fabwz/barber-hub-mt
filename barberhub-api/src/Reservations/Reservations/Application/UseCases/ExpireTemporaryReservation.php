<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class ExpireTemporaryReservation
{
    /** Receive the reservation repository, the lock of the collaborator, the expiry of overdue reservations and the transaction port. */
    public function __construct(
        private ReservationRepository $reservations,
        private CollaboratorLock $collaborators,
        private ExpireOverdueReservations $expireOverdue,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Run when the payment window of a temporary reservation closes (RE-05): if the reservation is still temporary and overdue, it locks its
     * collaborator, as every operation on their time does (RE-01), and expires the overdue reservations of that collaborator with the
     * same use case the bookings run, which announces each freed space. It decides nothing from the instant the task was made for:
     * a reservation that was paid, cancelled or already expired, one that does not exist and one whose window is still open are left
     * alone, so a retried or early task changes nothing.
     *
     * @return bool Whether the reservation was expired.
     */
    public function execute(string $reservationId, DateTimeImmutable $now): bool
    {
        return $this->transaction->run(function () use ($reservationId, $now): bool {
            $reservation = $this->reservations->find(new ReservationId($reservationId));

            if ($reservation === null || ! $reservation->isOverdueAt($now)) {
                return false;
            }

            $collaborator = $reservation->collaboratorId->value;
            $this->collaborators->lock($collaborator);

            return $this->expireOverdue->execute($collaborator, $now) > 0;
        });
    }
}
