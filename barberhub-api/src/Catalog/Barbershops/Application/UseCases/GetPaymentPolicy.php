<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class GetPaymentPolicy
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /** Give the payment policy in force now in the current barbershop (RF-08). */
    public function execute(): PaymentPolicy
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();

        return $barbershop->paymentPolicy;
    }
}
