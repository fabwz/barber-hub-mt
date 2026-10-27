<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetPaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;
use Src\Reservations\Reservations\Application\Contracts\PaymentRequirement;

final readonly class BarbershopsPaymentRequirement implements PaymentRequirement
{
    /** Receive the Barbershops use case that gives the payment policy. */
    public function __construct(private GetPaymentPolicy $getPaymentPolicy) {}

    /** Tell whether the current barbershop charges, through the Barbershops module. */
    public function isRequired(): bool
    {
        return $this->getPaymentPolicy->execute()->mode !== PaymentMode::None;
    }
}
