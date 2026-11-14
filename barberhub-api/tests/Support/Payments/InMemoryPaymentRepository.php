<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PurchasedVoucherId;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;

/** The payments in memory, for the tests of the use cases: it keeps the rule of one active payment per reservation or voucher, as the indexes do. */
final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<string, Payment> */
    private array $payments = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): PaymentId
    {
        return new PaymentId(sprintf('0192f0c4-3333-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the payment; a second active one for the same reservation or voucher is refused. */
    public function save(Payment $payment): void
    {
        $subject = $payment->status->isActive() ? $this->activeOfSubject($payment) : null;

        if ($subject !== null && ! $subject->id->equals($payment->id)) {
            throw ActivePaymentExistsException::create();
        }

        $this->payments[$payment->id->value] = $payment;
    }

    /** Find a payment by its identity. */
    public function find(PaymentId $id): ?Payment
    {
        return $this->payments[$id->value] ?? null;
    }

    /** Find a payment by its identity; there is nothing to lock in memory. */
    public function findForUpdate(PaymentId $id): ?Payment
    {
        return $this->find($id);
    }

    /** Find the pending or approved payment of the reservation. */
    public function findActiveOfReservation(ReservationId $reservationId): ?Payment
    {
        return array_find($this->payments, fn (Payment $payment): bool => $payment->status->isActive() && $payment->reservationId?->equals($reservationId) === true);
    }

    /** Find the pending or approved payment of the voucher. */
    public function findActiveOfVoucher(PurchasedVoucherId $voucherId): ?Payment
    {
        return array_find($this->payments, fn (Payment $payment): bool => $payment->status->isActive() && $payment->purchasedVoucherId?->equals($voucherId) === true);
    }

    /**
     * Find the pending or approved payments of several reservations.
     *
     * @param  list<ReservationId>  $reservationIds
     * @return array<string, Payment>
     */
    public function findActiveOfReservations(array $reservationIds): array
    {
        $found = [];

        foreach ($reservationIds as $id) {
            $payment = $this->findActiveOfReservation($id);

            if ($payment !== null) {
                $found[$id->value] = $payment;
            }
        }

        return $found;
    }

    /** How many payments are stored. */
    public function count(): int
    {
        return count($this->payments);
    }

    private function activeOfSubject(Payment $payment): ?Payment
    {
        return match (true) {
            $payment->reservationId !== null => $this->findActiveOfReservation($payment->reservationId),
            $payment->purchasedVoucherId !== null => $this->findActiveOfVoucher($payment->purchasedVoucherId),
            default => null,
        };
    }
}
