<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PaymentOutcome;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

/**
 * Saves a new payment and asks the provider to charge it. It is the one place that does it, for the payment of a reservation
 * (RF-20) and for the one of a voucher (RF-27), so no gateway logic is repeated. The caller holds the transaction.
 */
final readonly class ChargeNewPayment
{
    /** Receive the payment repository and the settlement. */
    public function __construct(private PaymentRepository $payments, private SettlePayment $settle) {}

    /**
     * The payment is saved before the provider is asked, so its identifier is in the provider's metadata. A parallel press that
     * won the unique index returns the payment that is already there. The three outcomes: approved or rejected come as the
     * provider says; if the provider cannot be reached before it created the charge the payment fails, and if it fails after (when
     * confirming) the payment stays pending for the status query to resolve and is never charged again by itself. If the
     * provider does not accept the data before creating the charge, the payment fails the same way and the outcome says so. An
     * approval of a reservation confirms it in the same transaction (PA-06); one of a voucher waits for the notice (BO-02).
     */
    public function execute(Payment $payment, PaymentGateway $gateway, ChargeRequest $request, DateTimeImmutable $now): PaymentOutcome
    {
        try {
            $this->payments->save($payment);
        } catch (ActivePaymentExistsException) {
            // A parallel press won the unique index: its payment is the one.
            return new PaymentOutcome($this->activeOf($payment) ?? throw ActivePaymentExistsException::create());
        }

        try {
            $result = $gateway->charge($request);
        } catch (GatewayUnavailableException $exception) {
            return $this->afterCommunicationError($payment, $exception, $now);
        } catch (GatewayRequestRejectedException $exception) {
            // The provider created no charge with our data: the payment fails and what it was for stays as it was, to try again.
            $payment->fail($exception->customerReason, $now);
            $this->payments->save($payment);

            return new PaymentOutcome($payment, true, null, false, true);
        }

        $payment->registerCharge($result->providerPaymentId, $result->providerAmount, $now);
        $this->settle->apply($payment, $this->statusToApply($payment, $result->status), $result->failureReason, $now);

        return new PaymentOutcome($payment, true, $result->nextAction);
    }

    /**
     * A voucher is activated only when the provider confirms its payment through the verified notice or the reading of its state
     * (BO-02, PA-03), never by the answer to the charge: an approval that comes with it leaves the payment pending, and the
     * notice that follows settles it. A refusal or a failure applies at once, since nothing is activated by them.
     */
    private function statusToApply(Payment $payment, PaymentStatus $reported): PaymentStatus
    {
        return $payment->isOfVoucher() && $reported === PaymentStatus::Approved ? PaymentStatus::Pending : $reported;
    }

    /** The pending or approved payment of the same reservation or voucher. */
    private function activeOf(Payment $payment): ?Payment
    {
        return match (true) {
            $payment->reservationId !== null => $this->payments->findActiveOfReservation($payment->reservationId),
            $payment->purchasedVoucherId !== null => $this->payments->findActiveOfVoucher($payment->purchasedVoucherId),
            default => null,
        };
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
}
