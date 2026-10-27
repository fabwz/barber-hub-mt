<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\Entities\Payment;

/**
 * A payment with what the caller needs to answer: whether this call created it, what the customer does next and whether the
 * provider could not be reached. The payment is already saved in every case, so the caller answers without rolling back.
 */
final readonly class PaymentOutcome
{
    public function __construct(
        public Payment $payment,
        public bool $created = false,
        public ?NextAction $nextAction = null,
        public bool $gatewayUnavailable = false,
    ) {}
}
