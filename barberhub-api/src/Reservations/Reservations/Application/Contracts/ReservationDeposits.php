<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;

interface ReservationDeposits
{
    /** Tell whether the reservation has a deposit (or the full price) paid online and approved, whether or not it is already marked to give back. */
    public function isPaid(string $reservationId): bool;

    /**
     * Mark the approved payment of the reservation as one to give back, once (CA-02, CA-04, CA-06). Marking again, or a reservation with
     * no approved payment, changes nothing. It never waits on a payment that another transaction is settling: if that one approves it
     * after the reservation is cancelled, it marks it itself (PA-07).
     */
    public function markToRefund(string $reservationId, DateTimeImmutable $now): void;
}
