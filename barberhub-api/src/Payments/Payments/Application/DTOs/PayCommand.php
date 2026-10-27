<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;

/** What the customer asks when they pay a reservation: the method and what that method needs. The amount is never part of it. */
final readonly class PayCommand
{
    public function __construct(
        public string $userId,
        public string $reservationId,
        public PaymentMethod $method,
        public ?string $cardToken = null,
        public ?string $phone = null,
        public ?string $identification = null,
        public ?string $identificationType = null,
    ) {}
}
