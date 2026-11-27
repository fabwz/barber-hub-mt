<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopDetails;
use Src\Catalog\Invitations\Application\Contracts\InvitingBarbershop;

final readonly class BarbershopsInvitingBarbershop implements InvitingBarbershop
{
    /** Receive the Barbershops use case that reads the current barbershop. */
    public function __construct(private GetBarbershopDetails $getBarbershop) {}

    /** Read the name of the barbershop through the Barbershops module. */
    public function name(): string
    {
        return $this->getBarbershop->execute()->name->value;
    }
}
