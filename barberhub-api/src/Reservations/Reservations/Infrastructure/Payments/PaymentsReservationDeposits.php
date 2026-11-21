<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Payments;

use DateTimeImmutable;
use Src\Payments\Payments\Application\UseCases\HasPaidDeposit;
use Src\Payments\Payments\Application\UseCases\MarkDepositToRefund;
use Src\Reservations\Reservations\Application\Contracts\ReservationDeposits;

final readonly class PaymentsReservationDeposits implements ReservationDeposits
{
    /** Receive the Payments use cases that tell whether a deposit was paid and mark it to give back. */
    public function __construct(private HasPaidDeposit $hasPaid, private MarkDepositToRefund $markToRefund) {}

    /** Ask Payments, which owns the payments. */
    public function isPaid(string $reservationId): bool
    {
        return $this->hasPaid->execute($reservationId);
    }

    /** Mark through Payments. */
    public function markToRefund(string $reservationId, DateTimeImmutable $now): void
    {
        $this->markToRefund->execute($reservationId, $now);
    }
}
