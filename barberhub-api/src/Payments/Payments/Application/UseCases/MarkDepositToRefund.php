<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;

final readonly class MarkDepositToRefund
{
    /** Receive the payment repository. */
    public function __construct(private PaymentRepository $payments) {}

    /**
     * Mark the approved payment of a reservation of the current barbershop as one to give back: the reservation was cancelled on time or by
     * the barbershop (CA-02, CA-04), and until the refund exists (RF-24) the mark is the whole consequence. It is one conditional write, so
     * marking again changes nothing (CA-06) and it never waits on a payment that is being settled (PA-07). A reservation with no approved
     * payment, or already marked or refunded, changes nothing.
     */
    public function execute(string $reservationId, DateTimeImmutable $now): void
    {
        $this->payments->markRefundPendingOfReservation(new ReservationId($reservationId), $now);
    }
}
