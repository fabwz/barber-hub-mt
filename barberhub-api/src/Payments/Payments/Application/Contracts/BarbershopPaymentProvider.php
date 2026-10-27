<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

interface BarbershopPaymentProvider
{
    /** The provider the current barbershop collects through now, or null when the owner has not chosen one (PA-02). */
    public function current(): ?PaymentProvider;
}
