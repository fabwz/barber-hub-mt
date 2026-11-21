<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;

final readonly class HasPaidDeposit
{
    /** Receive the payment repository. */
    public function __construct(private PaymentRepository $payments) {}

    /** Tell whether the reservation, of the current barbershop, has an approved payment, marked to give back or not. A pending one is not money yet. */
    public function execute(string $reservationId): bool
    {
        return $this->payments->findActiveOfReservation(new ReservationId($reservationId))?->status === PaymentStatus::Approved;
    }
}
