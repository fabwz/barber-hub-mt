<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\DTOs\UpdatePaymentPolicyCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentGateway;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class UpdatePaymentPolicy
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /**
     * Change the payment policy of the current barbershop (RF-08). A field that does not come keeps its current value, except
     * the percentage, which belongs to the deposit: it is kept only while the mode stays a deposit. The policy validates itself,
     * so a deposit of 5 % or 120 %, a deposit without percentage or a charge without gateway is rejected. The change applies
     * to the reservations and charges made from now on, never to those already made (PA-02, CA-01).
     */
    public function execute(UpdatePaymentPolicyCommand $command): PaymentPolicy
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
        $current = $barbershop->paymentPolicy;

        $mode = $command->mode === null ? $current->mode : PaymentMode::from($command->mode);
        $policy = PaymentPolicy::of(
            $mode,
            $command->depositPercentage ?? ($mode === PaymentMode::Deposit ? $current->depositPercentage : null),
            $command->cancellationDeadlineHours ?? $current->cancellationDeadlineHours,
            $command->gateway === null ? $current->gateway : PaymentGateway::from($command->gateway),
        );

        $this->barbershops->save($barbershop->withPaymentPolicy($policy));

        return $policy;
    }
}
