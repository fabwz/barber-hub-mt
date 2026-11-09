<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class GetBarbershopDetails
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /** Give the data the owner edits of the current barbershop (RF-01). */
    public function execute(): Barbershop
    {
        return $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
    }
}
