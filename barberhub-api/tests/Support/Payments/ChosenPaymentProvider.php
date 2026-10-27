<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** The provider a test barbershop has chosen, which the test can change as the owner would. */
final class ChosenPaymentProvider implements BarbershopPaymentProvider
{
    /** Start with the provider the barbershop has chosen, or none. */
    public function __construct(public ?PaymentProvider $provider = null) {}

    public function current(): ?PaymentProvider
    {
        return $this->provider;
    }
}
