<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\DTOs\PaymentLocation;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** Finds the payment a provider talks about, when there is no session and so no barbershop yet. It reads nothing else. */
interface PaymentLocator
{
    /** The barbershop and the identifier of the payment with that reference in the provider, or null when there is none. */
    public function locate(PaymentProvider $provider, string $reference): ?PaymentLocation;
}
