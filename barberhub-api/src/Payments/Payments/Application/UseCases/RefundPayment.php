<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\Exceptions\RefundRefusedException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\Exceptions\PaymentTransitionNotAllowedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

final readonly class RefundPayment
{
    /** Receive the payment repository and the gateway resolver. */
    public function __construct(private PaymentRepository $payments, private PaymentGatewayResolver $gateways) {}

    /**
     * Give the whole payment back through the gateway it was charged with (PA-08). Only an approved payment can be refunded.
     * If the provider accepts, the payment becomes refunded and loses the mark of one to give back; if it refuses, the
     * payment stays approved and the reason reaches the owner.
     *
     * @throws PaymentNotFoundException
     * @throws PaymentTransitionNotAllowedException when the payment is not approved
     * @throws RefundRefusedException
     * @throws PaymentServiceUnavailableException
     */
    public function execute(string $paymentId, DateTimeImmutable $now): Payment
    {
        $payment = $this->payments->find(new PaymentId($paymentId)) ?? throw PaymentNotFoundException::create();

        if (! $payment->status->canChangeTo(PaymentStatus::Refunded) || $payment->providerPaymentId === null) {
            throw PaymentTransitionNotAllowedException::create();
        }

        try {
            $result = $this->gateways->forPayment($payment)->refund($payment->providerPaymentId, $payment->amount);
        } catch (GatewayUnavailableException) {
            throw PaymentServiceUnavailableException::create();
        }

        if (! $result->accepted) {
            throw RefundRefusedException::because($result->failureReason);
        }

        $payment->refund($now);
        $this->payments->save($payment);

        return $payment;
    }
}
