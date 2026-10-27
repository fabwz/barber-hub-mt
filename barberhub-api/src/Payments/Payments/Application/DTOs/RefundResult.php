<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/** The answer of the provider to a refund: accepted, with its own identifier, or refused with the reason. */
final readonly class RefundResult
{
    private function __construct(public bool $accepted, public ?string $refundId, public ?string $failureReason) {}

    /** The provider accepted the refund. */
    public static function accepted(string $refundId): self
    {
        return new self(true, $refundId, null);
    }

    /** The provider refused the refund. */
    public static function refused(?string $reason = null): self
    {
        return new self(false, null, $reason);
    }
}
