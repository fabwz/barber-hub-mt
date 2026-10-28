<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\Contracts\PayerAccounts;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PayCommand;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Application\DTOs\PaymentOutcome;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Application\SettlePayment;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\Exceptions\ReservationNotPayableException;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class PayReservation
{
    /** Receive the repository, the ports to the neighbouring modules, the services of the module and the barbershop context. */
    public function __construct(
        private PaymentRepository $payments,
        private PayableReservations $reservations,
        private BarbershopChargePolicy $policy,
        private PayerAccounts $accounts,
        private PaymentGatewayResolver $gateways,
        private PaymentAmountCalculator $calculator,
        private SettlePayment $settle,
        private BarbershopContext $context,
    ) {}

    /**
     * Charge the customer for their temporary reservation (RF-20). The amount is the deposit or the total the policy of the
     * barbershop says over the price the reservation kept; nothing of it comes from the request (PA-06). A reservation with a
     * payment pending or approved returns that payment and charges nothing, so pressing the button twice never charges twice.
     * The payment is saved before the provider is asked, so its identifier is in the provider's metadata. The three outcomes:
     * approved or rejected come as the provider says; if the provider cannot be reached before it created the charge the
     * payment fails and the reservation stays temporary to try again, and if it fails after (when confirming) the payment
     * stays pending for the status query to resolve and is never charged again by itself. An approval confirms the reservation
     * in the same transaction. If the provider does not accept the data before creating the charge, the payment fails the same
     * way and the outcome says so, for a 422. It joins the transaction of the caller.
     *
     * @throws PaymentNotFoundException
     * @throws ReservationNotPayableException
     */
    public function execute(PayCommand $command, DateTimeImmutable $now): PaymentOutcome
    {
        $reservation = $this->reservations->ofCustomer($command->userId, $command->reservationId, $now);
        $reservationId = new ReservationId($reservation->id);

        $existing = $this->payments->findActiveOfReservation($reservationId);
        if ($existing !== null) {
            return new PaymentOutcome($existing);
        }

        $policy = $this->policy->current();
        if (! $policy->charges) {
            throw ReservationNotPayableException::notRequired();
        }
        if (! $reservation->open) {
            throw ReservationNotPayableException::notOpen();
        }

        $gateway = $this->gateways->forNewCharge($command->method);
        $amount = $policy->deposit === null ? $this->calculator->total($reservation->price) : $this->calculator->deposit($reservation->price, $policy->deposit);
        $payment = Payment::create($this->payments->nextId(), $this->context->current(), $reservationId, $gateway->provider(), $command->method, $amount, $now);

        try {
            $this->payments->save($payment);
        } catch (ActivePaymentExistsException) {
            // A parallel press won the unique index: its payment is the one.
            return new PaymentOutcome($this->payments->findActiveOfReservation($reservationId) ?? throw ActivePaymentExistsException::create());
        }

        try {
            $result = $gateway->charge(new ChargeRequest($payment->id, $reservationId, $amount, $command->method, $this->detailsOf($command)));
        } catch (GatewayUnavailableException $exception) {
            return $this->afterCommunicationError($payment, $exception, $now);
        } catch (GatewayRequestRejectedException) {
            // The provider created no charge with our data: the payment fails and the reservation stays temporary to try again.
            $payment->fail('gateway_request_rejected', $now);
            $this->payments->save($payment);

            return new PaymentOutcome($payment, true, null, false, true);
        }

        $payment->registerCharge($result->providerPaymentId, $result->providerAmount, $now);
        $this->settle->apply($payment, $result->status, $result->failureReason, $now);

        return new PaymentOutcome($payment, true, $result->nextAction);
    }

    /** Fail the payment when the provider never created the charge; keep it pending, with the charge, when it did. */
    private function afterCommunicationError(Payment $payment, GatewayUnavailableException $exception, DateTimeImmutable $now): PaymentOutcome
    {
        if ($exception->created === null) {
            $payment->fail('gateway_unavailable', $now);
        } else {
            $payment->registerCharge($exception->created->providerPaymentId, $exception->created->providerAmount, $now);
        }

        $this->payments->save($payment);

        return new PaymentOutcome($payment, true, null, true);
    }

    /** What the method needs besides the amount; the SINPE Móvil of the provider asks for the name and email of the account. */
    private function detailsOf(PayCommand $command): PaymentDetails
    {
        if ($command->method === PaymentMethod::Card) {
            return PaymentDetails::card($command->cardToken);
        }

        $account = $this->accounts->find($command->userId) ?? throw PaymentNotFoundException::create();

        return PaymentDetails::sinpeMovil((string) $command->phone, (string) $command->identification, (string) $command->identificationType, $account->name, $account->email);
    }
}
