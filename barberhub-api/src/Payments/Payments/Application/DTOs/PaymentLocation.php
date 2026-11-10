<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/** Where a payment lives: the barbershop it belongs to and its identifier. */
final readonly class PaymentLocation
{
    public function __construct(public string $barbershopId, public string $paymentId) {}
}
