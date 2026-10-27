<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\ValueObjects\Money;

/** What the gateway needs to create a charge: the amount in colones, the method and the references to find it again. */
final readonly class ChargeRequest
{
    public function __construct(
        public PaymentId $paymentId,
        public ReservationId $reservationId,
        public Money $amount,
        public PaymentMethod $method,
    ) {}
}
