<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\ChargeNewPayment;
use Src\Payments\Payments\Application\Contracts\PayableVouchers;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PaymentOutcome;
use Src\Payments\Payments\Application\DTOs\PayVoucherCommand;
use Src\Payments\Payments\Application\PaymentDetailsFactory;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\ValueObjects\PurchasedVoucherId;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class PayVoucher
{
    /** Receive the repository, the port to the vouchers, the services of the module, the barbershop context and the transaction port. */
    public function __construct(
        private PaymentRepository $payments,
        private PayableVouchers $vouchers,
        private PaymentDetailsFactory $details,
        private PaymentGatewayResolver $gateways,
        private ChargeNewPayment $charge,
        private BarbershopContext $context,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Buy a voucher (RF-27). In one transaction it registers the purchase (which creates the customer role if the person has none,
     * UP-09, and locks the voucher on sale so editing it cannot cross with the sale), and charges its price in full: the amount
     * is the one the voucher kept, never one from the request (PA-06), and the deposit policy of the barbershop does not apply to
     * it. A voucher with a payment pending or approved returns that payment and charges nothing, so pressing the button twice
     * never charges twice. This use case never activates the voucher: even an immediate approval of the provider leaves the
     * payment pending, and only its verified notice (or the reading of its state) approves it and activates the voucher (BO-02, PA-03). If the provider cannot be reached or
     * does not accept the data the payment fails and the voucher stays unpaid; the outcome says so and the transaction commits,
     * so the attempt stays on record.
     *
     * @throws PaymentNotFoundException when the voucher is not on sale in the barbershop
     */
    public function execute(PayVoucherCommand $command, DateTimeImmutable $now): PaymentOutcome
    {
        return $this->transaction->run(function () use ($command, $now): PaymentOutcome {
            $voucher = $this->vouchers->begin($command->userId, $command->voucherDefinitionId, $now);
            $voucherId = new PurchasedVoucherId($voucher->id);

            $existing = $this->payments->findActiveOfVoucher($voucherId);
            if ($existing !== null) {
                return new PaymentOutcome($existing);
            }

            $gateway = $this->gateways->forNewCharge($command->method);
            $payment = Payment::createForVoucher($this->payments->nextId(), $this->context->current(), $voucherId, $gateway->provider(), $command->method, $voucher->price, $now);
            $request = new ChargeRequest(
                $payment->id,
                null,
                $voucher->price,
                $command->method,
                $this->details->make($command->userId, $command->method, $command->cardToken, $command->phone, $command->identification, $command->identificationType),
                $voucherId,
            );

            return $this->charge->execute($payment, $gateway, $request, $now);
        });
    }
}
