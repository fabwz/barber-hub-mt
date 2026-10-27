<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Contracts;

use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;

interface PaymentRepository
{
    /** Generate the identity for a new payment. */
    public function nextId(): PaymentId;

    /**
     * Persist a new payment, or the changes of an existing one.
     *
     * @throws ActivePaymentExistsException When the reservation already has a payment pending or approved.
     */
    public function save(Payment $payment): void;

    /** Find a payment among those of the current barbershop. */
    public function find(PaymentId $id): ?Payment;

    /** Find the pending or approved payment of a reservation of the current barbershop: the one a repeated press of the pay button must reuse. */
    public function findActiveOfReservation(ReservationId $reservationId): ?Payment;
}
