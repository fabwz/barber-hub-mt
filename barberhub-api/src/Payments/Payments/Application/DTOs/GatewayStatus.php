<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

/** The state of a charge according to the provider, translated to the statuses of a payment, with the capture that settled it if there is one. */
final readonly class GatewayStatus
{
    public function __construct(public PaymentStatus $status, public ?string $failureReason = null, public ?string $captureId = null) {}
}
