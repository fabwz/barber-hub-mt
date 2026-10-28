<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Src\Payments\Payments\Application\DTOs\GatewayStatus;
use Src\Payments\Payments\Application\DTOs\RefundResult;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\RefundNotSupportedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * What the system asks of a payment provider. There is one adapter per provider in Infrastructure, and each one converts
 * currency and units on its own side: everything that goes in and out of this port is in colones. A refusal of the provider
 * is a result; only a failure to talk to it is an exception (RF-23).
 */
interface PaymentGateway
{
    /** The provider this adapter talks to. */
    public function provider(): PaymentProvider;

    /** Tell whether the provider admits the payment method (PA-02). */
    public function supports(PaymentMethod $method): bool;

    /**
     * Create the charge in the provider. The result says whether it is already decided and, when the customer still has to do
     * something (go to a page, pay with SINPE Móvil), what.
     *
     * @throws GatewayUnavailableException When the provider cannot be reached, answers with a 5xx or refuses our credentials.
     * @throws GatewayRequestRejectedException When the provider does not accept the data it was sent.
     */
    public function charge(ChargeRequest $request): ChargeResult;

    /**
     * Ask the provider for the state of a charge it created. The system does not receive notices in this stage, so it asks.
     * The identifier of our payment is only for the log.
     *
     * @throws GatewayUnavailableException When the provider cannot be reached, answers with a 5xx or refuses our credentials.
     * @throws GatewayRequestRejectedException When the provider does not accept the question.
     */
    public function status(string $providerPaymentId, PaymentId $paymentId): GatewayStatus;

    /**
     * Ask the provider to give the money of a charge back. The identifier of our payment is only for the log.
     *
     * @throws GatewayUnavailableException When the provider cannot be reached, answers with a 5xx or refuses our credentials.
     * @throws RefundNotSupportedException When the adapter does not implement refunds.
     */
    public function refund(string $providerPaymentId, Money $amount, PaymentId $paymentId): RefundResult;
}
