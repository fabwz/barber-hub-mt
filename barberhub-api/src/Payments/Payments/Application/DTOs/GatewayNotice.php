<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/**
 * A notice of a provider whose origin was verified, reduced to what the system uses: who sent it, its identifier, its type and
 * the identifier the provider gives to the charge it is about. A notice with no reference is not about a payment of ours
 * (another event type, a test event, a live-mode event) and changes nothing. The notice only says "ask me": the state always
 * comes from the provider's API.
 */
final readonly class GatewayNotice
{
    public function __construct(
        public PaymentProvider $provider,
        public string $eventId,
        public string $eventType,
        public ?string $reference,
    ) {}
}
