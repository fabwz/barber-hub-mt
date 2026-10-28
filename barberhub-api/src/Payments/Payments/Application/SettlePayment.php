<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

/**
 * Applies to a pending payment what the provider says. An approval and the confirmation of the reservation are saved
 * together, so there is never a confirmed reservation without its payment nor an approved payment without its reservation
 * (PA-06); the caller holds the transaction. The one exception is a payment approved after the reservation can no longer be
 * confirmed: it stays approved and marked to give back (PA-07).
 */
final readonly class SettlePayment
{
    /** Receive the payment repository and the port that confirms reservations. */
    public function __construct(private PaymentRepository $payments, private ReservationConfirmation $reservations) {}

    /** Move the payment to the status the provider reported and save it; a status that is still pending changes nothing. */
    public function apply(Payment $payment, PaymentStatus $status, ?string $reason, DateTimeImmutable $now, ?string $captureId = null): void
    {
        match ($status) {
            PaymentStatus::Approved => $this->approve($payment, $now, $captureId),
            PaymentStatus::Rejected => $payment->reject($reason, $now),
            PaymentStatus::Failed => $payment->fail($reason, $now),
            PaymentStatus::Pending, PaymentStatus::Refunded => null,
        };

        $this->payments->save($payment);
    }

    /** Approve the payment and confirm its reservation, or mark the payment to give back when the reservation cannot be confirmed. */
    private function approve(Payment $payment, DateTimeImmutable $now, ?string $captureId): void
    {
        $payment->approve($now, $captureId);

        if (! $this->reservations->confirm($payment->reservationId->value, $now)) {
            $payment->markRefundPending($now);
        }
    }
}
