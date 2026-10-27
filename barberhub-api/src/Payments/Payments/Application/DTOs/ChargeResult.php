<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;

/** What the provider answered when the charge was created: its identifier, what it charged, how far it is and what the customer does next. */
final readonly class ChargeResult
{
    public function __construct(
        public string $providerPaymentId,
        public ProviderAmount $providerAmount,
        public PaymentStatus $status,
        public ?string $failureReason = null,
        public ?NextAction $nextAction = null,
    ) {}
}
