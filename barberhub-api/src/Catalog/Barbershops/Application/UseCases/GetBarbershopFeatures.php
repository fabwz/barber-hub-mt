<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class GetBarbershopFeatures
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /** Give the switches of the current barbershop: vouchers and waitlist (RF-09). */
    public function execute(): BarbershopFeatures
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();

        return $barbershop->features;
    }
}
