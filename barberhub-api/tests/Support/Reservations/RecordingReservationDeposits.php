<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationDeposits;

/** The deposits port of the reservations for the unit tests: it says whether a deposit is paid and records every mark to give back. */
final class RecordingReservationDeposits implements ReservationDeposits
{
    /** Whether the reservation has an approved payment. */
    public bool $paid = false;

    /** @var list<string> The reservations marked to give back, one entry per call. */
    public array $marked = [];

    /** Answer whether a deposit is paid. */
    public function isPaid(string $reservationId): bool
    {
        return $this->paid;
    }

    /** Record the mark. */
    public function markToRefund(string $reservationId, DateTimeImmutable $now): void
    {
        $this->marked[] = $reservationId;
    }
}
