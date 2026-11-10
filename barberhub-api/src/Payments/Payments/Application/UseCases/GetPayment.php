<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

final readonly class GetPayment
{
    /** Receive the payment repository, the synchronization with the provider and the port that finds reservations. */
    public function __construct(
        private PaymentRepository $payments,
        private SyncPaymentWithGateway $sync,
        private PayableReservations $reservations,
    ) {}

    /**
     * Show a payment, asking the provider for its state when it is still pending: the system never trusts the browser, and
     * a notice of the provider only triggers the same question (RF-21, RF-22, PA-06). The answer updates the payment and,
     * when it is approved, confirms the reservation in the same transaction as the caller. A pending payment that never
     * reached the provider is left as it is. The owner of the barbershop reads any payment of it; the customer only those of
     * their own reservations, and anything else reads like a payment that does not exist (UP-06).
     *
     * @throws PaymentNotFoundException
     * @throws PaymentServiceUnavailableException when the provider cannot be asked
     * @throws PaymentRequestRejectedException when the provider does not accept the question
     */
    public function execute(string $userId, bool $isOwner, string $paymentId, DateTimeImmutable $now): Payment
    {
        $payment = $this->payments->find(new PaymentId($paymentId)) ?? throw PaymentNotFoundException::create();

        if (! $isOwner) {
            $this->reservations->ofCustomer($userId, $payment->reservationId->value, $now);
        }

        if ($payment->status !== PaymentStatus::Pending || $payment->providerPaymentId === null) {
            return $payment;
        }

        return $this->sync->execute($payment->id, $now) ?? throw PaymentNotFoundException::create();
    }
}
