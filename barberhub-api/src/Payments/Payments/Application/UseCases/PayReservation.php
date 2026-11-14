<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\ChargeNewPayment;
use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PayCommand;
use Src\Payments\Payments\Application\DTOs\PaymentOutcome;
use Src\Payments\Payments\Application\PaymentDetailsFactory;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\Exceptions\ReservationNotPayableException;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class PayReservation
{
    /** Receive the repository, the ports to the neighbouring modules, the services of the module and the barbershop context. */
    public function __construct(
        private PaymentRepository $payments,
        private PayableReservations $reservations,
        private BarbershopChargePolicy $policy,
        private PaymentDetailsFactory $details,
        private PaymentGatewayResolver $gateways,
        private PaymentAmountCalculator $calculator,
        private ChargeNewPayment $charge,
        private BarbershopContext $context,
    ) {}

    /**
     * Charge the customer for their temporary reservation (RF-20). The amount is the deposit or the total the policy of the
     * barbershop says over the price the reservation kept; nothing of it comes from the request (PA-06). A reservation with a
     * payment pending or approved returns that payment and charges nothing, so pressing the button twice never charges twice.
     * The charge itself, with its outcomes and the confirmation of the reservation on approval, is the one of ChargeNewPayment,
     * which the purchase of a voucher shares: if the provider cannot be reached or does not accept the data the payment fails
     * and the reservation stays temporary to try again. It joins the transaction of the caller.
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
        $amount = $policy->amountOf($reservation->price, $this->calculator);
        $payment = Payment::create($this->payments->nextId(), $this->context->current(), $reservationId, $gateway->provider(), $command->method, $amount, $now);
        $request = new ChargeRequest(
            $payment->id,
            $reservationId,
            $amount,
            $command->method,
            $this->details->make($command->userId, $command->method, $command->cardToken, $command->phone, $command->identification, $command->identificationType),
        );

        return $this->charge->execute($payment, $gateway, $request, $now);
    }
}
