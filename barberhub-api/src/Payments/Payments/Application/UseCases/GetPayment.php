<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Application\SettlePayment;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

final readonly class GetPayment
{
    /** Receive the payment repository, the gateway resolver, the settlement and the port that finds reservations. */
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayResolver $gateways,
        private SettlePayment $settle,
        private PayableReservations $reservations,
    ) {}

    /**
     * Show a payment, asking the provider for its state when it is still pending: the system never trusts the browser, and
     * it receives no webhooks in this stage (RF-21, PA-06). The answer updates the payment and, when it is approved,
     * confirms the reservation in the same transaction as the caller. A pending payment that never reached the provider
     * is left as it is. The owner of the barbershop reads any payment of it; the customer only those of their own
     * reservations, and anything else reads like a payment that does not exist (UP-06).
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

        try {
            $state = $this->gateways->forPayment($payment)->status($payment->providerPaymentId, $payment->id);
        } catch (GatewayUnavailableException) {
            throw PaymentServiceUnavailableException::create();
        } catch (GatewayRequestRejectedException) {
            throw PaymentRequestRejectedException::create();
        }

        $this->settle->apply($payment, $state->status, $state->failureReason, $now);

        return $payment;
    }
}
