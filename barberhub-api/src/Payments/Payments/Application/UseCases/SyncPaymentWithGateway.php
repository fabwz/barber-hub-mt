<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Application\SettlePayment;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

/**
 * Brings a payment to the state its provider reports: it locks the payment, asks the provider while it is still pending
 * and settles it, which confirms the reservation when it was approved (PA-06). It does not know who asks: the customer or the
 * owner reading it, or a notice of the provider acting as the system. The lock makes a read and a notice of the same payment run in
 * turn, and the one that comes second finds it already settled. The caller holds the transaction.
 */
final readonly class SyncPaymentWithGateway
{
    /** Receive the payment repository, the gateway resolver and the settlement. */
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayResolver $gateways,
        private SettlePayment $settle,
    ) {}

    /**
     * The payment as it stands after asking the provider. A payment that is not pending, or that never reached the provider,
     * is returned as it is, and the provider is not asked.
     *
     * @throws PaymentServiceUnavailableException when the provider cannot be asked
     * @throws PaymentRequestRejectedException when the provider does not accept the question
     */
    public function execute(PaymentId $id, DateTimeImmutable $now): ?Payment
    {
        $payment = $this->payments->findForUpdate($id);

        if ($payment === null || $payment->status !== PaymentStatus::Pending || $payment->providerPaymentId === null) {
            return $payment;
        }

        try {
            $state = $this->gateways->forPayment($payment)->status($payment->providerPaymentId, $payment->id);
        } catch (GatewayUnavailableException) {
            throw PaymentServiceUnavailableException::create();
        } catch (GatewayRequestRejectedException) {
            throw PaymentRequestRejectedException::create();
        }

        $this->settle->apply($payment, $state->status, $state->failureReason, $now, $state->captureId);

        return $payment;
    }
}
