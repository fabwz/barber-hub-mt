<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** How a barbershop charges: nothing up front, a percentage of the price or all of it (PA-01). */
enum PaymentMode: string
{
    case None = 'none';
    case Deposit = 'deposit';
    case Full = 'full';

    /** Whether the mode asks the customer to pay when booking, so it needs a gateway (PA-02). */
    public function charges(): bool
    {
        return $this !== self::None;
    }
}
